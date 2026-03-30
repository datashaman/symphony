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

    public function __construct(
        private WorkflowConfig $config,
        private LoggerInterface $logger,
    ) {
        $this->root = $config->workspaceRoot();
    }

    public function pathForIssue(Issue $issue): string
    {
        $key = preg_replace('/[^A-Za-z0-9._-]/', '_', $issue->identifier);
        $path = $this->root . '/' . $key;

        // Ensure workspace root exists for realpath check
        if (!is_dir($this->root)) {
            mkdir($this->root, 0755, true);
        }

        $realRoot = realpath($this->root);
        // Check for traversal: the computed path must be under root
        // We check the resolved parent since the workspace dir might not exist yet
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

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
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

        if (!is_dir($path)) {
            return;
        }

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

        $this->recursiveDelete($path);
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
                // Read remaining output
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                break;
            }

            $elapsedMs = (hrtime(true) - $startTime) / 1_000_000;
            if ($elapsedMs > $timeoutMs) {
                proc_terminate($process, 15); // SIGTERM
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                throw new RuntimeException(
                    "Hook '{$phase}' timed out after {$timeoutMs}ms: {$command}"
                );
            }

            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            usleep(10000); // 10ms
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

        // We need issue IDs from workspace directory names to check states
        // The workspace key IS the sanitized identifier, so we pass them as IDs
        $ids = array_values($workspaceDirs);
        $states = $tracker->fetchStatesByIds($ids);
        $terminalStates = array_map('strtolower', $this->config->trackerTerminalStates());

        foreach ($states as $id => $state) {
            if (in_array(strtolower($state), $terminalStates, true)) {
                $path = $this->root . '/' . $id;
                $this->logger->info("Cleaning up terminal workspace: {$id}", [
                    'state' => $state,
                ]);

                $hooks = $this->config->workspaceHooks();
                if (isset($hooks['before_remove'])) {
                    foreach ((array) $hooks['before_remove'] as $command) {
                        try {
                            $this->runHook('before_remove', $command, $path);
                        } catch (RuntimeException $e) {
                            $this->logger->warning("before_remove hook failed during cleanup: {$e->getMessage()}");
                        }
                    }
                }

                $this->recursiveDelete($path);
            }
        }
    }

    private function recursiveDelete(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
