<?php

use App\Config\WorkflowConfig;
use App\Tracker\Issue;
use App\Workspace\WorkspaceManager;
use Psr\Log\NullLogger;

function makeWsConfig(string $root, array $hooks = []): WorkflowConfig
{
    putenv('WS_API_KEY=test');
    $config = new WorkflowConfig([
        'tracker' => [
            'kind' => 'github',
            'api_key' => '$WS_API_KEY',
        ],
        'workspace' => [
            'root' => $root,
            'hooks' => $hooks,
        ],
    ]);
    putenv('WS_API_KEY');

    return $config;
}

function makeTestIssue(string $identifier = 'symphony#42'): Issue
{
    return new Issue(
        id: '42',
        identifier: $identifier,
        title: 'Test',
        description: '',
        priority: null,
        state: 'Todo',
        branchName: 'symphony/test',
        url: '',
        labels: [],
        blockedBy: [],
        createdAt: new DateTimeImmutable('now'),
        updatedAt: new DateTimeImmutable('now'),
    );
}

it('computes workspace path with identifier sanitization', function () {
    $root = sys_get_temp_dir() . '/symphony_test_' . uniqid();
    $config = makeWsConfig($root);
    $manager = new WorkspaceManager($config, new NullLogger());

    $path = $manager->pathForIssue(makeTestIssue('symphony#42'));

    expect($path)->toEndWith('/symphony_42');
    expect(str_starts_with($path, realpath($root)))->toBeTrue();

    rmdir($root);
});

it('prevents path traversal', function () {
    $root = sys_get_temp_dir() . '/symphony_test_' . uniqid();
    mkdir($root, 0755, true);
    $config = makeWsConfig($root);
    $manager = new WorkspaceManager($config, new NullLogger());

    // The sanitizer replaces all non-safe chars with _, so ../.. becomes __..__
    // This means the path won't actually escape. Let's verify the path stays under root.
    $path = $manager->pathForIssue(makeTestIssue('../../etc/passwd'));
    expect(str_starts_with($path, realpath($root)))->toBeTrue();

    // Clean up
    if (is_dir($root)) {
        rmdir($root);
    }
});

it('creates workspace directory and runs after_create hook', function () {
    $root = sys_get_temp_dir() . '/symphony_test_' . uniqid();
    $config = makeWsConfig($root, [
        'after_create' => ['touch created.txt'],
    ]);
    $manager = new WorkspaceManager($config, new NullLogger());
    $issue = makeTestIssue('test-issue');

    $path = $manager->create($issue);

    expect(is_dir($path))->toBeTrue();
    expect(file_exists($path . '/created.txt'))->toBeTrue();

    // Clean up
    unlink($path . '/created.txt');
    rmdir($path);
    rmdir($root);
});

it('removes workspace directory', function () {
    $root = sys_get_temp_dir() . '/symphony_test_' . uniqid();
    $config = makeWsConfig($root);
    $manager = new WorkspaceManager($config, new NullLogger());
    $issue = makeTestIssue('remove-test');

    $path = $manager->create($issue);
    expect(is_dir($path))->toBeTrue();

    $manager->remove($issue);
    expect(is_dir($path))->toBeFalse();

    // Clean up root
    if (is_dir($root)) {
        rmdir($root);
    }
});

it('treats after_create hook failure as fatal', function () {
    $root = sys_get_temp_dir() . '/symphony_test_' . uniqid();
    $config = makeWsConfig($root, [
        'after_create' => ['exit 1'],
    ]);
    $manager = new WorkspaceManager($config, new NullLogger());
    $issue = makeTestIssue('fatal-hook');

    $manager->create($issue);
})->throws(RuntimeException::class, 'after_create');

it('treats before_remove hook failure as non-fatal', function () {
    $root = sys_get_temp_dir() . '/symphony_test_' . uniqid();
    $config = makeWsConfig($root, [
        'before_remove' => ['exit 1'],
    ]);
    $manager = new WorkspaceManager($config, new NullLogger());
    $issue = makeTestIssue('nonfatal-hook');

    $path = $manager->create($issue);
    expect(is_dir($path))->toBeTrue();

    // Should not throw despite hook failure
    $manager->remove($issue);
    expect(is_dir($path))->toBeFalse();

    // Clean up
    if (is_dir($root)) {
        rmdir($root);
    }
});

it('enforces hook timeout', function () {
    $root = sys_get_temp_dir() . '/symphony_test_' . uniqid();
    putenv('WS_API_KEY=test');
    $config = new WorkflowConfig([
        'tracker' => [
            'kind' => 'github',
            'api_key' => '$WS_API_KEY',
        ],
        'workspace' => ['root' => $root],
        'hooks' => ['timeout_ms' => 100], // Very short timeout
    ]);
    putenv('WS_API_KEY');

    $manager = new WorkspaceManager($config, new NullLogger());
    mkdir($root, 0755, true);

    $manager->runHook('after_create', 'sleep 10', $root);
})->throws(RuntimeException::class, 'timed out');
