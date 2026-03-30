<?php

use App\Workflow\WorkflowLoader;

it('parses a valid workflow file', function () {
    $content = <<<'WORKFLOW'
---
tracker:
  kind: github
  api_key: $GITHUB_TOKEN
---
Fix {{ issue.identifier }}: {{ issue.title }}
WORKFLOW;

    $loader = new WorkflowLoader('/dev/null');
    $result = $loader->parse($content);

    expect($result)->toHaveKeys(['config', 'prompt']);
    expect($result['config']['tracker']['kind'])->toBe('github');
    expect($result['config']['tracker']['api_key'])->toBe('$GITHUB_TOKEN');
    expect($result['prompt'])->toContain('Fix {{ issue.identifier }}');
});

it('throws on missing front matter delimiters', function () {
    $content = "No front matter here\nJust a prompt";

    $loader = new WorkflowLoader('/dev/null');
    $loader->parse($content);
})->throws(InvalidArgumentException::class, 'missing YAML front matter delimiters');

it('throws on empty prompt body', function () {
    $content = "---\ntracker:\n  kind: github\n---\n   \n";

    $loader = new WorkflowLoader('/dev/null');
    $loader->parse($content);
})->throws(InvalidArgumentException::class, 'empty prompt template');

it('throws on invalid YAML', function () {
    $content = "---\n: invalid: yaml: {{{\n---\nSome prompt";

    $loader = new WorkflowLoader('/dev/null');
    $loader->parse($content);
})->throws(InvalidArgumentException::class, 'Invalid YAML');

it('loads from file on disk', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'workflow_');
    file_put_contents($tmpFile, "---\ntracker:\n  kind: jira\n---\nDo the thing\n");

    $loader = new WorkflowLoader($tmpFile);
    $result = $loader->load();

    expect($result['config']['tracker']['kind'])->toBe('jira');
    expect($result['prompt'])->toContain('Do the thing');

    unlink($tmpFile);
});

it('supports reload by re-reading file', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'workflow_');
    file_put_contents($tmpFile, "---\ntracker:\n  kind: github\n---\nPrompt v1\n");

    $loader = new WorkflowLoader($tmpFile);
    $result1 = $loader->load();
    expect($result1['prompt'])->toContain('Prompt v1');

    file_put_contents($tmpFile, "---\ntracker:\n  kind: jira\n---\nPrompt v2\n");

    $result2 = $loader->load();
    expect($result2['config']['tracker']['kind'])->toBe('jira');
    expect($result2['prompt'])->toContain('Prompt v2');

    unlink($tmpFile);
});
