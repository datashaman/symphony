<?php

use App\Config\WorkflowConfig;

it('applies all defaults when only required fields provided', function () {
    putenv('TEST_API_KEY=ghp_test123');

    $config = new WorkflowConfig([
        'tracker' => [
            'kind' => 'github',
            'api_key' => '$TEST_API_KEY',
        ],
    ]);

    expect($config->trackerKind())->toBe('github');
    expect($config->trackerApiKey())->toBe('ghp_test123');
    expect($config->trackerActiveStates())->toBe(['Todo', 'In Progress']);
    expect($config->trackerTerminalStates())->toBe(['Closed', 'Cancelled', 'Canceled', 'Duplicate', 'Done']);
    expect($config->pollingIntervalMs())->toBe(30000);
    expect($config->maxConcurrentAgents())->toBe(10);
    expect($config->maxTurns())->toBe(20);
    expect($config->maxRetryBackoffMs())->toBe(300000);
    expect($config->claudeCommand())->toBe('claude -p --output-format stream-json');
    expect($config->claudeTurnTimeoutMs())->toBe(3600000);
    expect($config->claudeStallTimeoutMs())->toBe(300000);

    putenv('TEST_API_KEY');
});

it('allows overriding defaults', function () {
    putenv('TEST_API_KEY=ghp_test123');

    $config = new WorkflowConfig([
        'tracker' => [
            'kind' => 'github',
            'api_key' => '$TEST_API_KEY',
        ],
        'polling' => [
            'interval_ms' => 60000,
        ],
        'agent' => [
            'max_concurrent_agents' => 5,
        ],
    ]);

    expect($config->pollingIntervalMs())->toBe(60000);
    expect($config->maxConcurrentAgents())->toBe(5);
    // Other defaults still apply
    expect($config->maxTurns())->toBe(20);

    putenv('TEST_API_KEY');
});

it('resolves $VAR environment variables', function () {
    putenv('MY_GITHUB_TOKEN=ghp_abc123');

    $config = new WorkflowConfig([
        'tracker' => [
            'kind' => 'github',
            'api_key' => '$MY_GITHUB_TOKEN',
        ],
    ]);

    expect($config->trackerApiKey())->toBe('ghp_abc123');

    putenv('MY_GITHUB_TOKEN');
});

it('resolves ${VAR} environment variables', function () {
    putenv('MY_GITHUB_TOKEN=ghp_abc456');

    $config = new WorkflowConfig([
        'tracker' => [
            'kind' => 'github',
            'api_key' => '${MY_GITHUB_TOKEN}',
        ],
    ]);

    expect($config->trackerApiKey())->toBe('ghp_abc456');

    putenv('MY_GITHUB_TOKEN');
});

it('throws on unset environment variable', function () {
    putenv('NONEXISTENT_VAR_FOR_TEST'); // ensure unset

    new WorkflowConfig([
        'tracker' => [
            'kind' => 'github',
            'api_key' => '$NONEXISTENT_VAR_FOR_TEST',
        ],
    ]);
})->throws(InvalidArgumentException::class, "Environment variable 'NONEXISTENT_VAR_FOR_TEST' is not set");

it('throws on unsupported tracker kind', function () {
    putenv('TEST_API_KEY=test');

    try {
        new WorkflowConfig([
            'tracker' => [
                'kind' => 'linear',
                'api_key' => '$TEST_API_KEY',
            ],
        ]);
    } finally {
        putenv('TEST_API_KEY');
    }
})->throws(InvalidArgumentException::class, 'Unsupported tracker kind');

it('throws on missing tracker kind', function () {
    putenv('TEST_API_KEY=test');

    try {
        new WorkflowConfig([
            'tracker' => [
                'api_key' => '$TEST_API_KEY',
            ],
        ]);
    } finally {
        putenv('TEST_API_KEY');
    }
})->throws(InvalidArgumentException::class, 'Missing required config: tracker.kind');

it('throws on missing api key', function () {
    new WorkflowConfig([
        'tracker' => [
            'kind' => 'github',
        ],
    ]);
})->throws(InvalidArgumentException::class, 'Missing required config: tracker.api_key');
