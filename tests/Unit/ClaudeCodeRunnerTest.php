<?php

use App\Agent\ClaudeCodeRunner;
use App\Config\WorkflowConfig;
use Psr\Log\NullLogger;

function makeRunnerConfig(string $command, int $turnTimeoutMs = 3600000, int $stallTimeoutMs = 300000): WorkflowConfig
{
    putenv('RUNNER_API_KEY=test');
    $config = new WorkflowConfig([
        'tracker' => [
            'kind' => 'github',
            'api_key' => '$RUNNER_API_KEY',
        ],
        'codex' => [
            'command' => $command,
            'turn_timeout_ms' => $turnTimeoutMs,
            'stall_timeout_ms' => $stallTimeoutMs,
        ],
    ]);
    putenv('RUNNER_API_KEY');

    return $config;
}

it('runs a normal agent turn and parses JSON output', function () {
    // Create a mock script that outputs JSON lines
    $script = tempnam(sys_get_temp_dir(), 'claude_mock_');
    file_put_contents($script, <<<'BASH'
#!/bin/bash
echo '{"type":"system","session_id":"sess_123"}'
echo '{"type":"result","usage":{"input_tokens":100,"output_tokens":50}}'
exit 0
BASH
    );
    chmod($script, 0755);

    $config = makeRunnerConfig("bash {$script}");
    $runner = new ClaudeCodeRunner($config, new NullLogger());
    $result = $runner->runTurn('test prompt', sys_get_temp_dir());

    expect($result['success'])->toBeTrue();
    expect($result['session_id'])->toBe('sess_123');
    expect($result['tokens']['input_tokens'])->toBe(100);
    expect($result['tokens']['output_tokens'])->toBe(50);

    unlink($script);
});

it('handles non-zero exit code', function () {
    $script = tempnam(sys_get_temp_dir(), 'claude_mock_');
    file_put_contents($script, <<<'BASH'
#!/bin/bash
echo '{"type":"system","session_id":"sess_fail"}'
exit 1
BASH
    );
    chmod($script, 0755);

    $config = makeRunnerConfig("bash {$script}");
    $runner = new ClaudeCodeRunner($config, new NullLogger());
    $result = $runner->runTurn('test prompt', sys_get_temp_dir());

    expect($result['success'])->toBeFalse();
    expect($result['session_id'])->toBe('sess_fail');

    unlink($script);
});

it('enforces turn timeout', function () {
    $script = tempnam(sys_get_temp_dir(), 'claude_mock_');
    file_put_contents($script, <<<'BASH'
#!/bin/bash
sleep 10
BASH
    );
    chmod($script, 0755);

    $config = makeRunnerConfig("bash {$script}", turnTimeoutMs: 200);
    $runner = new ClaudeCodeRunner($config, new NullLogger());
    $result = $runner->runTurn('test prompt', sys_get_temp_dir());

    expect($result['success'])->toBeFalse();

    unlink($script);
});

it('enforces stall timeout', function () {
    $script = tempnam(sys_get_temp_dir(), 'claude_mock_');
    file_put_contents($script, <<<'BASH'
#!/bin/bash
echo '{"type":"system","session_id":"sess_stall"}'
sleep 10
BASH
    );
    chmod($script, 0755);

    $config = makeRunnerConfig("bash {$script}", stallTimeoutMs: 200);
    $runner = new ClaudeCodeRunner($config, new NullLogger());
    $result = $runner->runTurn('test prompt', sys_get_temp_dir());

    expect($result['success'])->toBeFalse();
    expect($result['session_id'])->toBe('sess_stall');

    unlink($script);
});

it('runs multi-turn and stops on success', function () {
    $script = tempnam(sys_get_temp_dir(), 'claude_mock_');
    file_put_contents($script, <<<'BASH'
#!/bin/bash
echo '{"type":"result","usage":{"input_tokens":10,"output_tokens":5}}'
exit 0
BASH
    );
    chmod($script, 0755);

    $config = makeRunnerConfig("bash {$script}");
    $runner = new ClaudeCodeRunner($config, new NullLogger());
    $result = $runner->run('test prompt', sys_get_temp_dir());

    expect($result['success'])->toBeTrue();
    expect($result['tokens']['input_tokens'])->toBe(10);
    expect($result['tokens']['output_tokens'])->toBe(5);

    unlink($script);
});

it('enforces max turns', function () {
    $script = tempnam(sys_get_temp_dir(), 'claude_mock_');
    file_put_contents($script, <<<'BASH'
#!/bin/bash
echo '{"type":"result","usage":{"input_tokens":5,"output_tokens":3}}'
exit 1
BASH
    );
    chmod($script, 0755);

    putenv('MAX_TURNS_API_KEY=test');
    $config = new WorkflowConfig([
        'tracker' => [
            'kind' => 'github',
            'api_key' => '$MAX_TURNS_API_KEY',
        ],
        'agent' => [
            'max_turns' => 2,
        ],
        'codex' => [
            'command' => "bash {$script}",
        ],
    ]);
    putenv('MAX_TURNS_API_KEY');

    $runner = new ClaudeCodeRunner($config, new NullLogger());
    $result = $runner->run('test prompt', sys_get_temp_dir());

    expect($result['success'])->toBeFalse();
    // 2 turns * 5 input tokens = 10
    expect($result['tokens']['input_tokens'])->toBe(10);

    unlink($script);
});
