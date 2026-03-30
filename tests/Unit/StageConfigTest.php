<?php

use App\Config\StageConfig;

it('creates a stage config with all fields', function () {
    $stage = new StageConfig(
        ['name' => 'plan', 'trigger' => 'stage:plan', 'command' => 'claude -p --model opus', 'max_turns' => 5],
        ['command' => 'claude -p', 'turn_timeout_ms' => 3600000, 'stall_timeout_ms' => 300000, 'max_turns' => 20],
        'Plan the implementation for {{ issue.title }}'
    );

    expect($stage->name)->toBe('plan');
    expect($stage->trigger)->toBe('stage:plan');
    expect($stage->command)->toBe('claude -p --model opus');
    expect($stage->maxTurns)->toBe(5);
    expect($stage->turnTimeoutMs)->toBe(3600000);
    expect($stage->stallTimeoutMs)->toBe(300000);
    expect($stage->prompt)->toBe('Plan the implementation for {{ issue.title }}');
});

it('falls back to claude defaults for unspecified fields', function () {
    $stage = new StageConfig(
        ['name' => 'implement', 'trigger' => 'stage:implement'],
        ['command' => 'claude -p --model sonnet', 'turn_timeout_ms' => 1800000, 'stall_timeout_ms' => 150000, 'max_turns' => 20],
        'Implement the plan'
    );

    expect($stage->command)->toBe('claude -p --model sonnet');
    expect($stage->maxTurns)->toBe(20);
    expect($stage->turnTimeoutMs)->toBe(1800000);
    expect($stage->stallTimeoutMs)->toBe(150000);
});

it('normalizes trigger to lowercase', function () {
    $stage = new StageConfig(
        ['name' => 'plan', 'trigger' => 'Stage:Plan'],
        ['command' => 'claude -p', 'turn_timeout_ms' => 3600000, 'stall_timeout_ms' => 300000],
        'Plan it'
    );

    expect($stage->trigger)->toBe('stage:plan');
});

it('throws on missing name', function () {
    new StageConfig(
        ['trigger' => 'stage:plan'],
        ['command' => 'claude -p', 'turn_timeout_ms' => 3600000, 'stall_timeout_ms' => 300000],
        'Plan it'
    );
})->throws(InvalidArgumentException::class, 'must have a name');

it('throws on missing trigger', function () {
    new StageConfig(
        ['name' => 'plan'],
        ['command' => 'claude -p', 'turn_timeout_ms' => 3600000, 'stall_timeout_ms' => 300000],
        'Plan it'
    );
})->throws(InvalidArgumentException::class, 'must have a trigger label');
