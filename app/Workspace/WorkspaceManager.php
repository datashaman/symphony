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
        $this->root = $config->workspaceRoot() ?: $this->repoRoot.'/.symphony/worktrees';
    }

    public function pathForIssue(Issue $issue): string
    {
        $key = preg_replace('/[^A-Za-z0-9._-]/', '-', $issue->identifier);
        $path = $this->root.'/'.$key;

        if (! is_dir($this->root)) {
            mkdir($this->root, 0755, true);
        }

        $realRoot = realpath($this->root);
        $parentDir = dirname($path);
        if (! is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }
        $realParent = realpath($parentDir);

        if ($realParent === false || ! str_starts_with($realParent, $realRoot)) {
            throw new RuntimeException(
                'Path traversal detected: workspace path escapes root directory'
            );
        }

        return $realRoot.'/'.$key;
    }

    public function create(Issue $issue): string
    {
        $path = $this->pathForIssue($issue);
        $branch = $issue->branchName;

        if ($this->worktreeExists($path)) {
            // Worktree already exists — reuse it
            $this->logger->info('Reusing existing worktree', [
                'branch' => $branch,
                'path' => $path,
            ]);
        } else {
            // Clean up stale directory if it exists but isn't a registered worktree
            if (is_dir($path)) {
                $this->logger->info('Removing stale workspace directory', ['path' => $path]);
                $this->recursiveDelete($path);
                $this->gitSafe('worktree prune');
            }

            if ($this->branchExists($branch)) {
                // Branch exists — check if it's already checked out in a worktree
                $existingPath = $this->findWorktreeForBranch($branch);
                if ($existingPath) {
                    // Use the existing worktree instead of creating a new one
                    $this->logger->info('Using existing worktree for branch', [
                        'branch' => $branch,
                        'path' => $existingPath,
                    ]);

                    return $existingPath;
                }

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
            // Run setup commands for new worktrees
            $this->runSetup($path);
        }

        return $path;
    }

    public function remove(Issue $issue): void
    {
        $path = $this->pathForIssue($issue);
        $branch = $issue->branchName;

        // Remove worktree
        if (is_dir($path)) {
            $this->gitSafe("worktree remove --force {$this->escape($path)}");
        } else {
            $this->gitSafe('worktree prune');
        }

        // Delete the branch
        $this->gitSafe("branch -D {$this->escape($branch)}");
    }

    private function runSetup(string $workspacePath): void
    {
        $commands = $this->config->workspaceSetup();
        if (empty($commands)) {
            return;
        }

        foreach ($commands as $command) {
            // Substitute %BASE% with the repo root path
            $command = str_replace('%BASE%', $this->repoRoot, $command);
            $this->runHook('setup', $command, $workspacePath);
        }
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

        if (! is_resource($process)) {
            throw new RuntimeException("Failed to start hook process: {$command}");
        }

        fclose($pipes[0]);

        $timeoutMs = $this->config->setupTimeoutMs();
        $startTime = hrtime(true);
        $stdout = '';
        $stderr = '';

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $status = proc_get_status($process);
            if (! $status['running']) {
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
            $isFatal = in_array($phase, ['setup', 'after_create', 'before_run']);
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
        $this->gitSafe('worktree prune');

        if (! is_dir($this->root)) {
            return;
        }

        // Get worktrees registered under our root
        $worktrees = $this->listWorktrees();
        if (empty($worktrees)) {
            return;
        }

        $this->logger->info('Checking existing workspaces for terminal issues', [
            'count' => count($worktrees),
        ]);

        // Extract issue IDs from worktree dir names and check their states
        $ids = [];
        foreach ($worktrees as $wt) {
            $dirName = basename($wt['path']);
            // Dir name is sanitized identifier — extract the issue ID
            // GitHub: "dispatch_12" -> "12", Jira: "PROJ-123" -> "PROJ-123"
            if (preg_match('/(\d+)$/', $dirName, $matches)) {
                $ids[$matches[1]] = $wt;
            } else {
                $ids[$dirName] = $wt;
            }
        }

        $states = $tracker->fetchStatesByIds(array_keys($ids));
        $terminalStates = array_map('strtolower', $this->config->trackerTerminalStates());

        foreach ($states as $id => $state) {
            if (in_array(strtolower($state), $terminalStates, true)) {
                $wt = $ids[$id];
                $this->logger->info('Cleaning up terminal worktree', [
                    'id' => $id,
                    'state' => $state,
                    'path' => $wt['path'],
                    'branch' => $wt['branch'],
                ]);

                $this->gitSafe("worktree remove --force {$this->escape($wt['path'])}");
                if ($wt['branch']) {
                    $this->gitSafe("branch -D {$this->escape($wt['branch'])}");
                }
            }
        }
    }

    /**
     * List worktrees under the workspace root.
     *
     * @return array<array{path: string, branch: string|null}>
     */
    private function listWorktrees(): array
    {
        $output = $this->git('worktree list --porcelain');
        $worktrees = [];
        $current = [];

        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, 'worktree ')) {
                $current = ['path' => substr($line, 9), 'branch' => null];
            } elseif (str_starts_with($line, 'branch ')) {
                $current['branch'] = basename(substr($line, 7));
            } elseif ($line === '' && ! empty($current)) {
                // Only include worktrees under our root
                if (isset($current['path']) && str_starts_with($current['path'], $this->root)) {
                    $worktrees[] = $current;
                }
                $current = [];
            }
        }

        // Handle last entry (no trailing newline)
        if (! empty($current) && isset($current['path']) && str_starts_with($current['path'], $this->root)) {
            $worktrees[] = $current;
        }

        return $worktrees;
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

    private function worktreeExists(string $path): bool
    {
        $output = $this->git('worktree list --porcelain');
        $realPath = is_dir($path) ? realpath($path) : $path;

        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, 'worktree ')) {
                $worktreePath = substr($line, 9);
                if ($worktreePath === $realPath || $worktreePath === $path) {
                    return true;
                }
            }
        }

        return false;
    }

    private function findWorktreeForBranch(string $branch): ?string
    {
        $output = $this->git('worktree list --porcelain');
        $currentPath = null;

        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, 'worktree ')) {
                $currentPath = substr($line, 9);
            } elseif (str_starts_with($line, 'branch refs/heads/')) {
                $wtBranch = substr($line, 18);
                if ($wtBranch === $branch) {
                    return $currentPath;
                }
            } elseif ($line === '') {
                $currentPath = null;
            }
        }

        return null;
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
            throw new RuntimeException("git {$args} failed (exit {$exitCode}): ".implode("\n", $output));
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
