<?php

use App\Config\WorkflowConfig;

function makePipelineConfig(array $overrides = [], array $stagePrompts = []): WorkflowConfig
{
    putenv('TEST_API_KEY=test123');

    $defaults = [
        'tracker' => [
            'kind' => 'github',
            'api_key' => '$TEST_API_KEY',
        ],
        'pipeline' => [
            'stages' => [
                ['name' => 'plan', 'trigger' => 'stage:plan', 'command' => 'claude -p --model opus'],
                ['name' => 'implement', 'trigger' => 'stage:implement'],
            ],
        ],
    ];

    $config = new WorkflowConfig(
        array_replace_recursive($defaults, $overrides),
        $stagePrompts ?: ['plan' => 'Plan prompt', 'implement' => 'Implement prompt'],
    );

    putenv('TEST_API_KEY');

    return $config;
}

it('detects pipeline mode when stages are configured', function () {
    $config = makePipelineConfig();

    expect($config->hasPipeline())->toBeTrue();
    expect($config->stages())->toHaveCount(2);
});

it('reports no pipeline when stages are absent', function () {
    putenv('TEST_API_KEY=test123');
    $config = new WorkflowConfig([
        'tracker' => ['kind' => 'github', 'api_key' => '$TEST_API_KEY'],
    ]);
    putenv('TEST_API_KEY');

    expect($config->hasPipeline())->toBeFalse();
    expect($config->stages())->toBe([]);
});

it('builds stage configs with correct overrides', function () {
    $config = makePipelineConfig();
    $stages = $config->stages();

    expect($stages[0]->name)->toBe('plan');
    expect($stages[0]->command)->toBe('claude -p --model opus');
    expect($stages[0]->prompt)->toBe('Plan prompt');

    expect($stages[1]->name)->toBe('implement');
    // Falls back to global claude.command default
    expect($stages[1]->command)->toContain('claude -p');
    expect($stages[1]->prompt)->toBe('Implement prompt');
});

it('matches stage by issue labels', function () {
    $config = makePipelineConfig();

    $stage = $config->stageForLabels(['stage:plan']);
    expect($stage)->not->toBeNull();
    expect($stage->name)->toBe('plan');

    $stage = $config->stageForLabels(['stage:implement']);
    expect($stage)->not->toBeNull();
    expect($stage->name)->toBe('implement');

    $stage = $config->stageForLabels([]); // No stage trigger
    expect($stage)->toBeNull();
});

it('returns pipeline trigger labels', function () {
    $config = makePipelineConfig();

    expect($config->pipelineTriggerLabels())->toBe(['stage:plan', 'stage:implement']);
});

it('throws when stage prompt is missing', function () {
    putenv('TEST_API_KEY=test123');

    try {
        new WorkflowConfig([
            'tracker' => ['kind' => 'github', 'api_key' => '$TEST_API_KEY'],
            'pipeline' => [
                'stages' => [
                    ['name' => 'plan', 'trigger' => 'stage:plan'],
                ],
            ],
        ], ['implement' => 'Wrong stage name']); // Missing 'plan' prompt
    } finally {
        putenv('TEST_API_KEY');
    }
})->throws(InvalidArgumentException::class, 'has no matching prompt section');

it('allows per-stage max_turns override', function () {
    $config = makePipelineConfig([
        'pipeline' => [
            'stages' => [
                ['name' => 'plan', 'trigger' => 'stage:plan', 'max_turns' => 3],
                ['name' => 'implement', 'trigger' => 'stage:implement', 'max_turns' => 30],
            ],
        ],
    ]);

    expect($config->stages()[0]->maxTurns)->toBe(3);
    expect($config->stages()[1]->maxTurns)->toBe(30);
});
