<?php

it('exits with error on missing workflow file', function () {
    $this->artisan('run', ['workflow' => '/nonexistent/WORKFLOW.md'])
        ->expectsOutputToContain('Workflow file not found')
        ->assertExitCode(1);
});

it('exits with error on invalid workflow config', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'workflow_');
    file_put_contents($tmpFile, "---\ntracker:\n  kind: linear\n  api_key: test\n---\nPrompt\n");

    $this->artisan('run', ['workflow' => $tmpFile])
        ->expectsOutputToContain('Startup failed')
        ->assertExitCode(1);

    unlink($tmpFile);
});
