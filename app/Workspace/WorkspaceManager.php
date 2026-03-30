<?php

namespace App\Workspace;

use App\Config\WorkflowConfig;
use App\Tracker\Issue;
use App\Tracker\TrackerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class WorkspaceManager
{
    private string $root;
    private string $repoRoot;
    private string $baseBranch;

    public function __construct(
        private WorkflowConfig $config,
        private LoggerInterface $logger,
    ) {
        $this->repoRoot = $this->detectRepoRoot();
        $this->baseBranch = $this->detectBaseBranch();
        $this->root = $config->workspaceRoot() ?: $this->repoRoot . '/.symphony/worktrees';
    }

    public function pathForIssue(Issue $issue): string
    {
        $key = preg_replace('/[^A-Za-z0-9._-]/', '_', $issue->branchName);
        $path = $this->root . '/' . $key;

        if (!is_dir($this->root)) {
            mkdir($this->root, 0755, true);
        }

        $realRoot = realpath($this->root);
        $parentDir = dirname($path);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }
        $realParent = realpath($parentDir);

        if ($realParent === false || !str_starts_with($realParent, $realRoot)) {
            throw new RuntimeException(
                "Path traversal detected: workspace path escapes root directory"
            );
        }

        return $realRoot . '/' . $key;
    }

    public function create(Issue $issue): string
    {
        $path = $this->pathForIssue($issue);
        $branch = $issue->branchName;

        if (!is_dir($path)) {
            if ($this->branchExists($branch)) {
                $this->logger->info('Creating worktree with existing branch', [
                    'branch' => $branch,
                    'path' => $path,
                ]);
                $this->git("worktree add {$this->escape($path)} {$this->escape($branch)}");
            } else {
                $this->logger->info('Creating worktree with new branch', [
                    'branch' => $branch,
                    'base' => $this->baseBranch,
                    'path' => $path,
                ]);
                $this->git("worktree add {$this->escape($path)} -b {$this->escape($branch)} {$this->escape($this->baseBranch)}");
            }
        }

        $hooks = $this->config->workspaceHooks();
        if (isset($hooks['after_create'])) {
            foreach ((array) $hooks['after_create'] as $command) {
                $this->runHook('after_create', $command, $path);
            }
        }

        return $path;
    }

    public function remove(Issue $issue): void
    {
        $path = $this->pathForIssue($issue);
        $branch = $issue->branchName;

        $hooks = $this->config->workspaceHooks();
        if (isset($hooks['before_remove'])) {
            foreach ((array) $hooks['before_remove'] as $command) {
                try {
                    $this->runHook('before_remove', $command, $path);
                } catch (RuntimeException $e) {
                    $this->logger->warning("before_remove hook failed: {$e->getMessage()}");
                }
            }
        }

        // Remove worktree
        if (is_dir($path)) {
            $this->gitSafe("worktree remove --force {$this->escape($path)}");
        } else {
            $this->gitSafe('worktree prune');
        }

        // Delete the branch
        $this->gitSafe("branch -D {$this->escape($branch)}");
    }

    public function runHook(string $phase, string $command, string $workspacePath): void
    {
        $this->logger->info("Running {$phase} hook", [
            'command' => $command,
            'workspace' => $workspacePath,
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $workspacePath);

        if (!is_resource($process)) {
            throw new RuntimeException("Failed to start hook process: {$command}");
        }

        fclose($pipes[0]);

        $timeoutMs = $this->config->hooksTimeoutMs();
        $startTime = hrtime(true);
        $stdout = '';
        $stderr = '';

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                break;
            }

            $elapsedMs = (hrtime(true) - $startTime) / 1_000_000;
            if ($elapsedMs > $timeoutMs) {
                proc_terminate($process, 15);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                throw new RuntimeException(
                    "Hook '{$phase}' timed out after {$timeoutMs}ms: {$command}"
                );
            }

            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            usleep(10000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $isFatal = in_array($phase, ['after_create', 'before_run']);
            $message = "Hook '{$phase}' exited with code {$exitCode}: {$command}";

            if ($stderr) {
                $message .= "\nstderr: {$stderr}";
            }

            if ($isFatal) {
                throw new RuntimeException($message);
            }

            $this->logger->warning($message);
        }
    }

    public function cleanupTerminal(TrackerInterface $tracker): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $entries = scandir($this->root);
        if ($entries === false) {
            return;
        }

        $workspaceDirs = array_filter($entries, fn($e) => $e !== '.' && $e !== '..' && is_dir($this->root . '/' . $e));

        if (empty($workspaceDirs)) {
            return;
        }

        $this->logger->info('Checking existing workspaces for terminal issues', [
            'count' => count($workspaceDirs),
        ]);

        // Worktree dirs are named after the sanitized branchName.
        // We can't reverse-map to issue IDs reliably, so just prune
        // stale worktree references and leave cleanup to manual removal
        // or when the issue is re-fetched during a tick.
        $this->gitSafe('worktree prune');
    }

    private function detectRepoRoot(): string
    {
        $result = trim(shell_exec('git rev-parse --show-toplevel 2>/dev/null') ?? '');

        if ($result === '') {
            throw new RuntimeException(
                'Symphony must be run from within a git repository'
            );
        }

        return $result;
    }

    private function detectBaseBranch(): string
    {
        // Try origin/HEAD
        $ref = trim(shell_exec("git -C {$this->escape($this->repoRoot)} symbolic-ref refs/remotes/origin/HEAD 2>/dev/null") ?? '');
        if ($ref !== '') {
            return basename($ref);
        }

        // Fallback: check for main, then master
        $exitCode = 0;
        exec("git -C {$this->escape($this->repoRoot)} show-ref --verify --quiet refs/heads/main 2>/dev/null", $output, $exitCode);
        if ($exitCode === 0) {
            return 'main';
        }

        exec("git -C {$this->escape($this->repoRoot)} show-ref --verify --quiet refs/heads/master 2>/dev/null", $output, $exitCode);
        if ($exitCode === 0) {
            return 'master';
        }

        throw new RuntimeException('Cannot detect base branch (tried origin/HEAD, main, master)');
    }

    private function branchExists(string $branch): bool
    {
        $exitCode = 0;
        exec("git -C {$this->escape($this->repoRoot)} show-ref --verify --quiet refs/heads/{$this->escape($branch)} 2>/dev/null", $output, $exitCode);

        return $exitCode === 0;
    }

    private function git(string $args): string
    {
        $command = "git -C {$this->escape($this->repoRoot)} {$args} 2>&1";
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException("git {$args} failed (exit {$exitCode}): " . implode("\n", $output));
        }

        return implode("\n", $output);
    }

    private function gitSafe(string $args): void
    {
        $command = "git -C {$this->escape($this->repoRoot)} {$args} 2>&1";
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->logger->debug("git {$args} failed (non-fatal)", [
                'exit_code' => $exitCode,
                'output' => implode("\n", $output),
            ]);
        }
    }

    private function escape(string $arg): string
    {
        return escapeshellarg($arg);
    }
}
