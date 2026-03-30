<?php

use App\Config\WorkflowConfig;
use App\Tracker\Issue;
use App\Workspace\WorkspaceManager;
use Psr\Log\NullLogger;

function makeTestGitRepo(): string
{
    $repo = sys_get_temp_dir() . '/symphony_test_repo_' . uniqid();
    mkdir($repo, 0755, true);
    exec("git -C {$repo} init --initial-branch=main 2>&1");
    exec("git -C {$repo} commit --allow-empty -m 'init' 2>&1");

    return $repo;
}

function cleanupTestRepo(string $repo): void
{
    // Prune worktrees first, then delete
    exec("git -C {$repo} worktree prune 2>&1");
    exec("rm -rf {$repo} 2>&1");
}

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

function makeTestIssue(string $identifier = 'symphony#42', string $branchName = 'symphony/test-42'): Issue
{
    return new Issue(
        id: '42',
        identifier: $identifier,
        title: 'Test',
        description: '',
        priority: null,
        state: 'Todo',
        branchName: $branchName,
        url: '',
        labels: [],
        blockedBy: [],
        createdAt: new DateTimeImmutable('now'),
        updatedAt: new DateTimeImmutable('now'),
    );
}

it('computes workspace path with identifier sanitization', function () {
    $repo = makeTestGitRepo();
    $root = $repo . '/.symphony-worktrees';
    $config = makeWsConfig($root);

    $origDir = getcwd();
    chdir($repo);

    $manager = new WorkspaceManager($config, new NullLogger());
    $path = $manager->pathForIssue(makeTestIssue('sym#42', 'symphony/issue-42'));

    expect($path)->toEndWith('/sym-42');
    expect(str_starts_with($path, realpath($root)))->toBeTrue();

    chdir($origDir);
    cleanupTestRepo($repo);
});

it('prevents path traversal', function () {
    $repo = makeTestGitRepo();
    $root = $repo . '/.symphony-worktrees';
    $config = makeWsConfig($root);

    $origDir = getcwd();
    chdir($repo);

    $manager = new WorkspaceManager($config, new NullLogger());
    $path = $manager->pathForIssue(makeTestIssue('../../etc/passwd', '../../etc/passwd'));
    expect(str_starts_with($path, realpath($root)))->toBeTrue();

    chdir($origDir);
    cleanupTestRepo($repo);
});

it('creates worktree and runs after_create hook', function () {
    $repo = makeTestGitRepo();
    $root = $repo . '/.symphony-worktrees';
    $config = makeWsConfig($root, [
        'after_create' => ['touch created.txt'],
    ]);

    $origDir = getcwd();
    chdir($repo);

    $manager = new WorkspaceManager($config, new NullLogger());
    $issue = makeTestIssue('test-issue', 'symphony/test-create');
    $path = $manager->create($issue);

    expect(is_dir($path))->toBeTrue();
    expect(file_exists($path . '/created.txt'))->toBeTrue();

    // Verify it's a real worktree
    expect(file_exists($path . '/.git'))->toBeTrue();

    chdir($origDir);
    cleanupTestRepo($repo);
});

it('removes worktree and branch', function () {
    $repo = makeTestGitRepo();
    $root = $repo . '/.symphony-worktrees';
    $config = makeWsConfig($root);

    $origDir = getcwd();
    chdir($repo);

    $manager = new WorkspaceManager($config, new NullLogger());
    $issue = makeTestIssue('remove-test', 'symphony/test-remove');

    $path = $manager->create($issue);
    expect(is_dir($path))->toBeTrue();

    $manager->remove($issue);
    expect(is_dir($path))->toBeFalse();

    // Branch should be gone too
    $exitCode = 0;
    exec("git -C {$repo} show-ref --verify --quiet refs/heads/symphony/test-remove 2>/dev/null", $output, $exitCode);
    expect($exitCode)->not->toBe(0);

    chdir($origDir);
    cleanupTestRepo($repo);
});

it('treats after_create hook failure as fatal', function () {
    $repo = makeTestGitRepo();
    $root = $repo . '/.symphony-worktrees';
    $config = makeWsConfig($root, [
        'after_create' => ['exit 1'],
    ]);

    $origDir = getcwd();
    chdir($repo);

    try {
        $manager = new WorkspaceManager($config, new NullLogger());
        $issue = makeTestIssue('fatal-hook', 'symphony/test-fatal');
        $manager->create($issue);
    } finally {
        chdir($origDir);
        cleanupTestRepo($repo);
    }
})->throws(RuntimeException::class, 'after_create');

it('treats before_remove hook failure as non-fatal', function () {
    $repo = makeTestGitRepo();
    $root = $repo . '/.symphony-worktrees';
    $config = makeWsConfig($root, [
        'before_remove' => ['exit 1'],
    ]);

    $origDir = getcwd();
    chdir($repo);

    $manager = new WorkspaceManager($config, new NullLogger());
    $issue = makeTestIssue('nonfatal-hook', 'symphony/test-nonfatal');

    $path = $manager->create($issue);
    expect(is_dir($path))->toBeTrue();

    // Should not throw despite hook failure
    $manager->remove($issue);
    expect(is_dir($path))->toBeFalse();

    chdir($origDir);
    cleanupTestRepo($repo);
});

it('enforces hook timeout', function () {
    $repo = makeTestGitRepo();

    $origDir = getcwd();
    chdir($repo);

    putenv('WS_API_KEY=test');
    $config = new WorkflowConfig([
        'tracker' => [
            'kind' => 'github',
            'api_key' => '$WS_API_KEY',
        ],
        'workspace' => ['root' => $repo . '/.symphony-worktrees'],
        'hooks' => ['timeout_ms' => 100],
    ]);
    putenv('WS_API_KEY');

    $manager = new WorkspaceManager($config, new NullLogger());

    try {
        $manager->runHook('after_create', 'sleep 10', $repo);
    } finally {
        chdir($origDir);
        cleanupTestRepo($repo);
    }
})->throws(RuntimeException::class, 'timed out');

it('reuses existing branch on retry', function () {
    $repo = makeTestGitRepo();
    $root = $repo . '/.symphony-worktrees';
    $config = makeWsConfig($root);

    $origDir = getcwd();
    chdir($repo);

    $manager = new WorkspaceManager($config, new NullLogger());
    $issue = makeTestIssue('retry-test', 'symphony/test-retry');

    // First create
    $path = $manager->create($issue);
    expect(is_dir($path))->toBeTrue();

    // Add a file in the worktree to verify state persists
    file_put_contents($path . '/work.txt', 'progress');

    // Remove worktree but keep branch
    exec("git -C {$repo} worktree remove --force {$path} 2>&1");

    // Second create should reuse existing branch
    $path2 = $manager->create($issue);
    expect(is_dir($path2))->toBeTrue();
    // The branch exists, so the committed work from before would be there
    // (uncommitted work is lost with worktree remove, which is expected)

    chdir($origDir);
    cleanupTestRepo($repo);
});
