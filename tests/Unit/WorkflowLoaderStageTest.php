<?php

use App\Workflow\WorkflowLoader;

it('parses stage prompts from pipeline stages', function () {
    $content = <<<'WORKFLOW'
tracker:
  kind: github
  api_key: $GITHUB_TOKEN
pipeline:
  stages:
    - name: plan
      trigger: stage:plan
      prompt: "You are a planner. Analyze {{ issue.title }}."
    - name: implement
      trigger: stage:implement
      prompt: "You are an implementer. Build {{ issue.title }}."
WORKFLOW;

    $loader = new WorkflowLoader('/dev/null');
    $result = $loader->parse($content);

    expect($result)->toHaveKeys(['config', 'prompt', 'stage_prompts']);
    expect($result['prompt'])->toBe('');
    expect($result['stage_prompts'])->toHaveKeys(['plan', 'implement']);
    expect($result['stage_prompts']['plan'])->toContain('You are a planner');
    expect($result['stage_prompts']['implement'])->toContain('You are an implementer');
});

it('strips prompt from stage config after extraction', function () {
    $content = <<<'WORKFLOW'
tracker:
  kind: github
  api_key: $GITHUB_TOKEN
pipeline:
  stages:
    - name: plan
      trigger: stage:plan
      prompt: "Plan it."
WORKFLOW;

    $loader = new WorkflowLoader('/dev/null');
    $result = $loader->parse($content);

    // prompt should be removed from the stage config so it doesn't leak into StageConfig
    expect($result['config']['pipeline']['stages'][0])->not->toHaveKey('prompt');
});

it('returns empty stage_prompts for single-prompt workflow', function () {
    $content = <<<'WORKFLOW'
tracker:
  kind: github
  api_key: $GITHUB_TOKEN
prompt: "Fix {{ issue.identifier }}: {{ issue.title }}"
WORKFLOW;

    $loader = new WorkflowLoader('/dev/null');
    $result = $loader->parse($content);

    expect($result['stage_prompts'])->toBe([]);
    expect($result['prompt'])->toContain('Fix {{ issue.identifier }}');
});

it('still rejects empty prompt for non-pipeline workflows', function () {
    $content = "tracker:\n  kind: github\n";

    $loader = new WorkflowLoader('/dev/null');
    $loader->parse($content);
})->throws(InvalidArgumentException::class, 'empty prompt template');

it('allows pipeline workflow with no top-level prompt', function () {
    $content = <<<'WORKFLOW'
tracker:
  kind: github
  api_key: $GITHUB_TOKEN
pipeline:
  stages:
    - name: plan
      trigger: stage:plan
      prompt: "Plan the work for {{ issue.title }}."
WORKFLOW;

    $loader = new WorkflowLoader('/dev/null');
    $result = $loader->parse($content);

    expect($result['prompt'])->toBe('');
    expect($result['stage_prompts']['plan'])->toContain('Plan the work');
});
