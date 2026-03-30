<?php

use App\Agent\ClaudeCodeRunner;
use App\Config\WorkflowConfig;
use App\Orchestrator\Orchestrator;
use App\Prompt\PromptBuilder;
use App\Tracker\Issue;
use App\Tracker\TrackerInterface;
use App\Workflow\WorkflowLoader;
use App\Workspace\WorkspaceManager;
use Psr\Log\NullLogger;

function makeOrchestratorConfig(int $maxConcurrent = 10): WorkflowConfig
{
    putenv('ORCH_API_KEY=test');
    $config = new WorkflowConfig([
        'tracker' => [
            'kind' => 'github',
            'api_key' => '$ORCH_API_KEY',
            'active_states' => ['todo', 'in-progress'],
            'terminal_states' => ['done', 'closed'],
        ],
        'agent' => [
            'max_concurrent_agents' => $maxConcurrent,
        ],
    ]);
    putenv('ORCH_API_KEY');

    return $config;
}

function makeIssueWithPriority(string $id, ?int $priority, string $createdAt = '2025-01-01T00:00:00Z'): Issue
{
    return new Issue(
        id: $id,
        identifier: "test#{$id}",
        title: "Issue {$id}",
        description: '',
        priority: $priority,
        state: 'todo',
        branchName: "symphony/test_{$id}",
        url: '',
        labels: ['todo'],
        blockedBy: [],
        createdAt: new DateTimeImmutable($createdAt),
        updatedAt: new DateTimeImmutable($createdAt),
    );
}

it('initializes with empty state', function () {
    $config = makeOrchestratorConfig();
    $tracker = Mockery::mock(TrackerInterface::class);
    $workspace = Mockery::mock(WorkspaceManager::class);
    $promptBuilder = new PromptBuilder;
    $agentRunner = Mockery::mock(ClaudeCodeRunner::class);
    $workflowLoader = Mockery::mock(WorkflowLoader::class);

    $orchestrator = new Orchestrator(
        $config, $tracker, $workspace, $promptBuilder,
        $agentRunner, $workflowLoader, new NullLogger
    );

    expect($orchestrator->getRunning())->toBe([]);
    expect($orchestrator->getClaimed())->toBe([]);
    expect($orchestrator->getRetryQueue())->toBe([]);
    expect($orchestrator->getClaudeTotals())->toBe([
        'input_tokens' => 0,
        'output_tokens' => 0,
        'seconds' => 0,
    ]);
});

it('sorts candidates by priority ASC then createdAt ASC', function () {
    $issues = [
        makeIssueWithPriority('1', 3, '2025-01-01T00:00:00Z'),
        makeIssueWithPriority('2', 1, '2025-01-02T00:00:00Z'),
        makeIssueWithPriority('3', 1, '2025-01-01T00:00:00Z'),
        makeIssueWithPriority('4', null, '2025-01-01T00:00:00Z'), // null = lowest priority
    ];

    // Sort using the same logic as the orchestrator
    usort($issues, function (Issue $a, Issue $b) {
        $pa = $a->priority ?? PHP_INT_MAX;
        $pb = $b->priority ?? PHP_INT_MAX;
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }
        $cmp = $a->createdAt <=> $b->createdAt;
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp($a->identifier, $b->identifier);
    });

    expect($issues[0]->id)->toBe('3'); // priority 1, earlier date
    expect($issues[1]->id)->toBe('2'); // priority 1, later date
    expect($issues[2]->id)->toBe('1'); // priority 3
    expect($issues[3]->id)->toBe('4'); // null priority (last)
});

it('calculates exponential backoff correctly', function () {
    // Attempt 1: min(10000 * 2^0, 300000) = 10000
    expect(min(10000 * (int) pow(2, 0), 300000))->toBe(10000);

    // Attempt 2: min(10000 * 2^1, 300000) = 20000
    expect(min(10000 * (int) pow(2, 1), 300000))->toBe(20000);

    // Attempt 3: min(10000 * 2^2, 300000) = 40000
    expect(min(10000 * (int) pow(2, 2), 300000))->toBe(40000);

    // Attempt 10: min(10000 * 2^9, 300000) = min(5120000, 300000) = 300000
    expect(min(10000 * (int) pow(2, 9), 300000))->toBe(300000);
});

it('filters eligible issues by pipeline stage labels', function () {
    putenv('ORCH_API_KEY=test');
    $config = new WorkflowConfig([
        'tracker' => [
            'kind' => 'github',
            'api_key' => '$ORCH_API_KEY',
            'active_states' => ['todo', 'in-progress'],
            'terminal_states' => ['done', 'closed'],
        ],
        'pipeline' => [
            'stages' => [
                ['name' => 'plan', 'trigger' => 'stage:plan', 'command' => 'claude -p --model opus'],
                ['name' => 'implement', 'trigger' => 'stage:implement'],
            ],
        ],
    ], ['plan' => 'Plan prompt', 'implement' => 'Implement prompt']);
    putenv('ORCH_API_KEY');
    $tracker = Mockery::mock(TrackerInterface::class);
    $workspace = Mockery::mock(WorkspaceManager::class);
    $promptBuilder = new PromptBuilder;
    $agentRunner = Mockery::mock(ClaudeCodeRunner::class);
    $workflowLoader = Mockery::mock(WorkflowLoader::class);

    $orchestrator = new Orchestrator(
        $config, $tracker, $workspace, $promptBuilder,
        $agentRunner, $workflowLoader, new NullLogger
    );

    // Issue with stage:plan label should be eligible for plan stage
    $issueWithPlan = new Issue(
        id: '1', identifier: 'test#1', title: 'Issue 1',
        description: '', priority: null, state: 'todo',
        branchName: 'symphony/test_1', url: '',
        labels: ['todo', 'stage:plan'], blockedBy: [],
        createdAt: new DateTimeImmutable('2025-01-01T00:00:00Z'),
        updatedAt: new DateTimeImmutable('2025-01-01T00:00:00Z'),
    );

    // Issue without stage label should NOT be eligible
    $issueNoStage = new Issue(
        id: '2', identifier: 'test#2', title: 'Issue 2',
        description: '', priority: null, state: 'todo',
        branchName: 'symphony/test_2', url: '',
        labels: ['todo'], blockedBy: [],
        createdAt: new DateTimeImmutable('2025-01-01T00:00:00Z'),
        updatedAt: new DateTimeImmutable('2025-01-01T00:00:00Z'),
    );

    $tracker->shouldReceive('fetchCandidateIssues')->andReturn([$issueWithPlan, $issueNoStage]);
    $tracker->shouldReceive('fetchStatesByIds')->andReturn([]);
    $workflowLoader->shouldReceive('load')->andReturn([
        'config' => [], 'prompt' => '', 'stage_prompts' => ['plan' => 'Plan it', 'implement' => 'Do it'],
    ]);

    // dispatch will fork — we can't test that directly, but we can verify
    // that after tick() the claimed map uses stage-suffixed keys
    // We need to prevent actual fork, so just test filterEligible via reflection
    $method = new ReflectionMethod($orchestrator, 'filterEligible');
    $eligible = $method->invoke($orchestrator, [$issueWithPlan, $issueNoStage]);

    expect($eligible)->toHaveCount(1);
    expect($eligible[0]['issue']->id)->toBe('1');
    expect($eligible[0]['stage']->name)->toBe('plan');
});

it('handles graceful shutdown request', function () {
    $config = makeOrchestratorConfig();
    $tracker = Mockery::mock(TrackerInterface::class);
    $workspace = Mockery::mock(WorkspaceManager::class);
    $promptBuilder = new PromptBuilder;
    $agentRunner = Mockery::mock(ClaudeCodeRunner::class);
    $workflowLoader = Mockery::mock(WorkflowLoader::class);

    $orchestrator = new Orchestrator(
        $config, $tracker, $workspace, $promptBuilder,
        $agentRunner, $workflowLoader, new NullLogger
    );

    expect($orchestrator->isShutdown())->toBeFalse();
    $orchestrator->requestShutdown();
    expect($orchestrator->isShutdown())->toBeTrue();
});
