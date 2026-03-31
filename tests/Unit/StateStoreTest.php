<?php

use App\Config\StageConfig;
use App\State\StateStore;
use App\Tracker\Issue;

function createTempStore(): StateStore
{
    $path = sys_get_temp_dir().'/symphony_test_'.uniqid().'.sqlite';

    return new StateStore($path);
}

function makeStateTestIssue(string $id = '1'): Issue
{
    return new Issue(
        id: $id,
        identifier: "test#{$id}",
        title: "Test Issue {$id}",
        description: 'Description',
        priority: 1,
        state: 'open',
        branchName: "symphony/test_{$id}",
        url: "https://github.com/test/repo/issues/{$id}",
        labels: ['stage:plan'],
        blockedBy: [],
        createdAt: new DateTimeImmutable('2025-01-01T00:00:00Z'),
        updatedAt: new DateTimeImmutable('2025-01-01T00:00:00Z'),
    );
}

it('initializes with default daemon status', function () {
    $store = createTempStore();
    $status = $store->getDaemonStatus();

    expect($status['status'])->toBe('stopped');
    expect($status['pid'])->toBeNull();
});

it('marks daemon as running and stopped', function () {
    $store = createTempStore();

    $store->markRunning(12345, '/path/to/workflow.yml');
    $status = $store->getDaemonStatus();

    expect($status['status'])->toBe('running');
    expect((int) $status['pid'])->toBe(12345);
    expect($status['workflow_path'])->toBe('/path/to/workflow.yml');
    expect($status['started_at'])->not->toBeNull();

    $store->markStopped();
    $status = $store->getDaemonStatus();

    expect($status['status'])->toBe('stopped');
});

it('syncs and retrieves agents', function () {
    $store = createTempStore();
    $issue = makeStateTestIssue();

    $running = [
        '1' => [
            'pid' => 100,
            'issue' => $issue,
            'stage' => null,
            'startedAt' => hrtime(true),
        ],
    ];

    $store->syncAgents($running);
    $agents = $store->getAgents();

    expect($agents)->toHaveCount(1);
    expect($agents[0]['issue_identifier'])->toBe('test#1');
    expect($agents[0]['issue_title'])->toBe('Test Issue 1');
    expect((int) $agents[0]['pid'])->toBe(100);
});

it('syncs agents with stage config', function () {
    $store = createTempStore();
    $issue = makeStateTestIssue();
    $stage = new StageConfig(
        ['name' => 'plan', 'trigger' => 'stage:plan'],
        ['command' => 'claude -p', 'max_turns' => 10, 'turn_timeout_ms' => 3600000, 'stall_timeout_ms' => 300000],
        'Plan prompt'
    );

    $running = [
        '1' => [
            'pid' => 200,
            'issue' => $issue,
            'stage' => $stage,
            'startedAt' => hrtime(true),
        ],
    ];

    $store->syncAgents($running);
    $agents = $store->getAgents();

    expect($agents[0]['stage'])->toBe('plan');
});

it('syncs and retrieves claimed keys', function () {
    $store = createTempStore();

    $claimed = ['1' => true, '2:plan' => true, '3:implement' => true];

    $store->syncClaimed($claimed);
    $result = $store->getClaimed();

    expect($result)->toHaveCount(3);
    expect($result)->toContain('1');
    expect($result)->toContain('2:plan');
    expect($result)->toContain('3:implement');
});

it('syncs and retrieves retry queue', function () {
    $store = createTempStore();

    $dueAt = hrtime(true) + 10_000_000_000; // 10 seconds from now
    $retryQueue = [
        'issue-1' => [
            'attempt' => 2,
            'dueAt' => $dueAt,
            'error' => 'failure',
        ],
    ];

    $store->syncRetryQueue($retryQueue);
    $result = $store->getRetryQueue();

    expect($result)->toHaveCount(1);
    expect($result[0]['issue_id'])->toBe('issue-1');
    expect((int) $result[0]['attempt'])->toBe(2);
    expect($result[0]['error'])->toBe('failure');
});

it('syncs and retrieves token totals', function () {
    $store = createTempStore();

    $totals = [
        'input_tokens' => 50000,
        'output_tokens' => 12000,
        'seconds' => 45.5,
    ];

    $store->syncTokenTotals($totals);
    $result = $store->getTokenTotals();

    expect((int) $result['input_tokens'])->toBe(50000);
    expect((int) $result['output_tokens'])->toBe(12000);
    expect((float) $result['seconds'])->toBe(45.5);
});

it('flushes all state atomically', function () {
    $store = createTempStore();
    $issue = makeStateTestIssue();

    $running = [
        '1' => [
            'pid' => 300,
            'issue' => $issue,
            'stage' => null,
            'startedAt' => hrtime(true),
        ],
    ];
    $claimed = ['1' => true];
    $retryQueue = [];
    $totals = ['input_tokens' => 1000, 'output_tokens' => 500, 'seconds' => 10.0];

    $store->flush($running, $claimed, $retryQueue, $totals);

    expect($store->getAgents())->toHaveCount(1);
    expect($store->getClaimed())->toHaveCount(1);
    expect($store->getRetryQueue())->toHaveCount(0);
    expect((int) $store->getTokenTotals()['input_tokens'])->toBe(1000);
});

it('replaces previous state on re-flush', function () {
    $store = createTempStore();
    $issue1 = makeStateTestIssue('1');
    $issue2 = makeStateTestIssue('2');

    // First flush
    $store->flush(
        ['1' => ['pid' => 100, 'issue' => $issue1, 'stage' => null, 'startedAt' => hrtime(true)]],
        ['1' => true],
        [],
        ['input_tokens' => 100, 'output_tokens' => 50, 'seconds' => 1.0]
    );

    expect($store->getAgents())->toHaveCount(1);

    // Second flush with different data
    $store->flush(
        ['2' => ['pid' => 200, 'issue' => $issue2, 'stage' => null, 'startedAt' => hrtime(true)]],
        ['2' => true],
        ['1' => ['attempt' => 1, 'dueAt' => hrtime(true), 'error' => 'failed']],
        ['input_tokens' => 200, 'output_tokens' => 100, 'seconds' => 2.0]
    );

    $agents = $store->getAgents();
    expect($agents)->toHaveCount(1);
    expect($agents[0]['issue_identifier'])->toBe('test#2');
    expect($store->getRetryQueue())->toHaveCount(1);
    expect((int) $store->getTokenTotals()['input_tokens'])->toBe(200);
});

it('updates heartbeat timestamp', function () {
    $store = createTempStore();
    $store->markRunning(1, '');

    $before = $store->getDaemonStatus()['updated_at'];
    sleep(1); // Ensure different second
    $store->heartbeat();
    $after = $store->getDaemonStatus()['updated_at'];

    expect($after)->not->toBe($before);
});

it('initializes token totals with zeros', function () {
    $store = createTempStore();
    $totals = $store->getTokenTotals();

    expect((int) $totals['input_tokens'])->toBe(0);
    expect((int) $totals['output_tokens'])->toBe(0);
    expect((float) $totals['seconds'])->toBe(0.0);
});
