<?php

use App\Workflow\WorkflowLoader;

it('parses a valid workflow file', function () {
    $content = <<<'WORKFLOW'
tracker:
  kind: github
  api_key: $GITHUB_TOKEN
prompt: "Fix {{ issue.identifier }}: {{ issue.title }}"
WORKFLOW;

    $loader = new WorkflowLoader('/dev/null');
    $result = $loader->parse($content);

    expect($result)->toHaveKeys(['config', 'prompt']);
    expect($result['config']['tracker']['kind'])->toBe('github');
    expect($result['config']['tracker']['api_key'])->toBe('$GITHUB_TOKEN');
    expect($result['prompt'])->toContain('Fix {{ issue.identifier }}');
});

it('throws on invalid YAML', function () {
    $content = ': invalid: yaml: {{{';

    $loader = new WorkflowLoader('/dev/null');
    $loader->parse($content);
})->throws(InvalidArgumentException::class, 'Invalid YAML');

it('throws on empty prompt body', function () {
    $content = "tracker:\n  kind: github\n";

    $loader = new WorkflowLoader('/dev/null');
    $loader->parse($content);
})->throws(InvalidArgumentException::class, 'empty prompt template');

it('loads from file on disk', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'workflow_');
    file_put_contents($tmpFile, "tracker:\n  kind: jira\nprompt: Do the thing\n");

    $loader = new WorkflowLoader($tmpFile);
    $result = $loader->load();

    expect($result['config']['tracker']['kind'])->toBe('jira');
    expect($result['prompt'])->toContain('Do the thing');

    unlink($tmpFile);
});

it('supports reload by re-reading file', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'workflow_');
    file_put_contents($tmpFile, "tracker:\n  kind: github\nprompt: Prompt v1\n");

    $loader = new WorkflowLoader($tmpFile);
    $result1 = $loader->load();
    expect($result1['prompt'])->toContain('Prompt v1');

    file_put_contents($tmpFile, "tracker:\n  kind: jira\nprompt: Prompt v2\n");

    $result2 = $loader->load();
    expect($result2['config']['tracker']['kind'])->toBe('jira');
    expect($result2['prompt'])->toContain('Prompt v2');

    unlink($tmpFile);
});

it('strips prompt from config to avoid double-passing', function () {
    $content = "tracker:\n  kind: github\nprompt: Do the thing\n";

    $loader = new WorkflowLoader('/dev/null');
    $result = $loader->parse($content);

    expect($result['config'])->not->toHaveKey('prompt');
});
