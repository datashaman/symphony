<?php

use App\Workflow\WorkflowLoader;

it('parses stage prompts from workflow body', function () {
    $content = <<<'WORKFLOW'
---
tracker:
  kind: github
  api_key: $GITHUB_TOKEN
pipeline:
  stages:
    - name: plan
      trigger: stage:plan
    - name: implement
      trigger: stage:implement
---

Default prompt here

---stage:plan---

You are a planner. Analyze {{ issue.title }}.

---stage:implement---

You are an implementer. Build {{ issue.title }}.
WORKFLOW;

    $loader = new WorkflowLoader('/dev/null');
    $result = $loader->parse($content);

    expect($result)->toHaveKeys(['config', 'prompt', 'stage_prompts']);
    expect($result['prompt'])->toContain('Default prompt here');
    expect($result['stage_prompts'])->toHaveKeys(['plan', 'implement']);
    expect($result['stage_prompts']['plan'])->toContain('You are a planner');
    expect($result['stage_prompts']['implement'])->toContain('You are an implementer');
});

it('allows pipeline workflow with no default prompt', function () {
    $content = <<<'WORKFLOW'
---
tracker:
  kind: github
  api_key: $GITHUB_TOKEN
pipeline:
  stages:
    - name: plan
      trigger: stage:plan
---

---stage:plan---

Plan the work for {{ issue.title }}.
WORKFLOW;

    $loader = new WorkflowLoader('/dev/null');
    $result = $loader->parse($content);

    expect($result['prompt'])->toBe('');
    expect($result['stage_prompts']['plan'])->toContain('Plan the work');
});

it('returns empty stage_prompts for single-stage workflow', function () {
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

    expect($result['stage_prompts'])->toBe([]);
    expect($result['prompt'])->toContain('Fix {{ issue.identifier }}');
});

it('still rejects empty prompt for non-pipeline workflows', function () {
    $content = "---\ntracker:\n  kind: github\n---\n   \n";

    $loader = new WorkflowLoader('/dev/null');
    $loader->parse($content);
})->throws(InvalidArgumentException::class, 'empty prompt template');
