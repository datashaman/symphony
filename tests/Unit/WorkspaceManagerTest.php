<?php

use App\Config\WorkflowConfig;
use App\Tracker\Issue;
use App\Workspace\WorkspaceManager;
use Psr\Log\NullLogger;

function makeTestGitRepo(): string
{
    $repo = sys_get_temp_dir().'/symphony_test_repo_'.uniqid();
    mkdir($repo, 0755, true);
    exec("git -C {$repo} init --initial-branch=main 2>&1");
    exec("git -C {$repo} commit --allow-empty -m 'init' 2>&1");

    return $repo;
}

function cleanupTestRepo(string $repo): void
{
    exec("git -C {$repo} worktree prune 2>&1");
    exec("rm -rf {$repo} 2>&1");
}

function makeWsConfig(string $root, array $setup = []): WorkflowConfig
{
    putenv('WS_API_KEY=test');
    $config = new WorkflowConfig([
        'tracker' => [
            'kind' => 'github',
            'api_key' => '$WS_API_KEY',
        ],
        'workspace' => [
            'root' => $root,
            'setup' => $setup,
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
    $root = $repo.'/.symphony/worktrees';
    $config = makeWsConfig($root);

    $origDir = getcwd();
    chdir($repo);

    $manager = new WorkspaceManager($config, new NullLogger);
    $path = $manager->pathForIssue(makeTestIssue('sym#42', 'symphony/issue-42'));

    expect($path)->toEndWith('/sym-42');
    expect(str_starts_with($path, realpath($root)))->toBeTrue();

    chdir($origDir);
    cleanupTestRepo($repo);
});

it('prevents path traversal', function () {
    $repo = makeTestGitRepo();
    $root = $repo.'/.symphony/worktrees';
    $config = makeWsConfig($root);

    $origDir = getcwd();
    chdir($repo);

    $manager = new WorkspaceManager($config, new NullLogger);
    $path = $manager->pathForIssue(makeTestIssue('../../etc/passwd', '../../etc/passwd'));
    expect(str_starts_with($path, realpath($root)))->toBeTrue();

    chdir($origDir);
    cleanupTestRepo($repo);
});

it('creates worktree and runs setup commands', function () {
    $repo = makeTestGitRepo();
    $root = $repo.'/.symphony/worktrees';

    // Create a file in the repo root to copy during setup
    file_put_contents($repo.'/.env', 'APP_KEY=test123');

    $config = makeWsConfig($root, [
        'cp %BASE%/.env .',
        'touch setup-done.txt',
    ]);

    $origDir = getcwd();
    chdir($repo);

    $manager = new WorkspaceManager($config, new NullLogger);
    $issue = makeTestIssue('test-issue', 'symphony/test-create');
    $path = $manager->create($issue);

    expect(is_dir($path))->toBeTrue();
    expect(file_exists($path.'/.git'))->toBeTrue();
    expect(file_exists($path.'/.env'))->toBeTrue();
    expect(file_get_contents($path.'/.env'))->toBe('APP_KEY=test123');
    expect(file_exists($path.'/setup-done.txt'))->toBeTrue();

    chdir($origDir);
    cleanupTestRepo($repo);
});

it('does not run setup when reusing worktree', function () {
    $repo = makeTestGitRepo();
    $root = $repo.'/.symphony/worktrees';
    $config = makeWsConfig($root, [
        'echo "setup-ran" > setup-marker.txt',
    ]);

    $origDir = getcwd();
    chdir($repo);

    $manager = new WorkspaceManager($config, new NullLogger);
    $issue = makeTestIssue('reuse-test', 'symphony/test-reuse');

    // First create — setup runs
    $path = $manager->create($issue);
    expect(file_exists($path.'/setup-marker.txt'))->toBeTrue();

    // Remove the marker
    unlink($path.'/setup-marker.txt');

    // Second create — reuses worktree, setup should NOT run
    $path2 = $manager->create($issue);
    expect($path2)->toBe($path);
    expect(file_exists($path.'/setup-marker.txt'))->toBeFalse();

    chdir($origDir);
    cleanupTestRepo($repo);
});

it('removes worktree and branch', function () {
    $repo = makeTestGitRepo();
    $root = $repo.'/.symphony/worktrees';
    $config = makeWsConfig($root);

    $origDir = getcwd();
    chdir($repo);

    $manager = new WorkspaceManager($config, new NullLogger);
    $issue = makeTestIssue('remove-test', 'symphony/test-remove');

    $path = $manager->create($issue);
    expect(is_dir($path))->toBeTrue();

    $manager->remove($issue);
    expect(is_dir($path))->toBeFalse();

    $exitCode = 0;
    exec("git -C {$repo} show-ref --verify --quiet refs/heads/symphony/test-remove 2>/dev/null", $output, $exitCode);
    expect($exitCode)->not->toBe(0);

    chdir($origDir);
    cleanupTestRepo($repo);
});

it('setup failure is fatal', function () {
    $repo = makeTestGitRepo();
    $root = $repo.'/.symphony/worktrees';
    $config = makeWsConfig($root, ['exit 1']);

    $origDir = getcwd();
    chdir($repo);

    try {
        $manager = new WorkspaceManager($config, new NullLogger);
        $issue = makeTestIssue('fatal-setup', 'symphony/test-fatal');
        $manager->create($issue);
    } finally {
        chdir($origDir);
        cleanupTestRepo($repo);
    }
})->throws(RuntimeException::class, 'setup');

it('enforces setup timeout', function () {
    $repo = makeTestGitRepo();

    $origDir = getcwd();
    chdir($repo);

    putenv('WS_API_KEY=test');
    $config = new WorkflowConfig([
        'tracker' => [
            'kind' => 'github',
            'api_key' => '$WS_API_KEY',
        ],
        'workspace' => [
            'root' => $repo.'/.symphony/worktrees',
            'setup' => ['sleep 10'],
            'setup_timeout_ms' => 100,
        ],
    ]);
    putenv('WS_API_KEY');

    try {
        $manager = new WorkspaceManager($config, new NullLogger);
        $issue = makeTestIssue('timeout-test', 'symphony/test-timeout');
        $manager->create($issue);
    } finally {
        chdir($origDir);
        cleanupTestRepo($repo);
    }
})->throws(RuntimeException::class, 'timed out');

it('reuses existing branch on retry', function () {
    $repo = makeTestGitRepo();
    $root = $repo.'/.symphony/worktrees';
    $config = makeWsConfig($root);

    $origDir = getcwd();
    chdir($repo);

    $manager = new WorkspaceManager($config, new NullLogger);
    $issue = makeTestIssue('retry-test', 'symphony/test-retry');

    $path = $manager->create($issue);
    expect(is_dir($path))->toBeTrue();

    file_put_contents($path.'/work.txt', 'progress');

    exec("git -C {$repo} worktree remove --force {$path} 2>&1");

    $path2 = $manager->create($issue);
    expect(is_dir($path2))->toBeTrue();

    chdir($origDir);
    cleanupTestRepo($repo);
});
