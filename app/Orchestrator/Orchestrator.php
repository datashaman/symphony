<?php

namespace App\Orchestrator;

use App\Agent\ClaudeCodeRunner;
use App\Config\StageConfig;
use App\Config\WorkflowConfig;
use App\Prompt\PromptBuilder;
use App\Tracker\Issue;
use App\Tracker\TrackerInterface;
use App\Workflow\WorkflowLoader;
use App\Workspace\WorkspaceManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Orchestrator
{
    /** @var array<string, array{pid: int, issue: Issue, stage: ?StageConfig, startedAt: int, lastActivity: int}> */
    private array $running = [];

    /** @var array<string, true> */
    private array $claimed = [];

    /** @var array<string, array{attempt: int, dueAt: int, error: string}> */
    private array $retryQueue = [];

    /** @var array{input_tokens: int, output_tokens: int, seconds: float} */
    private array $claudeTotals = ['input_tokens' => 0, 'output_tokens' => 0, 'seconds' => 0];

    private bool $shutdown = false;

    public function __construct(
        private WorkflowConfig $config,
        private TrackerInterface $tracker,
        private WorkspaceManager $workspace,
        private PromptBuilder $promptBuilder,
        private ClaudeCodeRunner $agentRunner,
        private WorkflowLoader $workflowLoader,
        private LoggerInterface $logger,
        private ?OutputInterface $output = null,
    ) {}

    private function console(string $message): void
    {
        $this->output?->writeln($message);
    }

    public function requestShutdown(): void
    {
        $this->shutdown = true;
        $this->console('<comment>Shutting down, waiting for running workers...</comment>');
        $this->logger->info('Shutdown requested, waiting for running workers to complete');
    }

    public function isShutdown(): bool
    {
        return $this->shutdown;
    }

    public function run(): void
    {
        $this->console('<info>Symphony orchestrator started</info>');
        $this->console("  Polling every {$this->config->pollingIntervalMs()}ms, max {$this->config->maxConcurrentAgents()} concurrent agents");
        $this->logger->info('Orchestrator starting');

        // Ensure configured labels exist on the tracker
        try {
            $created = $this->tracker->ensureLabels($this->config->pipelineTriggerLabels());
            if (! empty($created)) {
                $this->console('  Created labels: '.implode(', ', $created));
            }
        } catch (\Exception $e) {
            $this->logger->warning("Failed to ensure labels: {$e->getMessage()}");
            $this->console("  <comment>Warning: failed to ensure labels: {$e->getMessage()}</comment>");
        }

        // Startup cleanup
        $this->workspace->cleanupTerminal($this->tracker);

        while (! $this->shutdown) {
            $this->tick();

            $sleepMs = $this->config->pollingIntervalMs();
            $this->sleepMs($sleepMs);
        }

        $this->waitForChildren();
        $this->console('<info>Symphony stopped</info>');
        $this->logger->info('Orchestrator stopped', ['totals' => $this->claudeTotals]);
    }

    public function tick(): void
    {
        // 1. Reconcile running issues
        $this->reconcile();

        // 2. Reload config (WorkflowLoader re-reads on each call)
        $this->reloadConfig();

        if ($this->shutdown) {
            return;
        }

        // 3. Fetch candidates
        $candidates = $this->tracker->fetchCandidateIssues();

        $this->logger->info('Tick', [
            'candidates' => count($candidates),
            'running' => count($this->running),
            'retry_queue' => count($this->retryQueue),
        ]);

        // 4. Sort: priority ASC, createdAt ASC, identifier ASC
        usort($candidates, function (Issue $a, Issue $b) {
            $pa = $a->priority ?? PHP_INT_MAX;
            $pb = $b->priority ?? PHP_INT_MAX;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            $cmp = $a->createdAt <=> $b->createdAt;
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp($a->identifier, $b->identifier);
        });

        // 5. Filter eligible
        $eligible = $this->filterEligible($candidates);

        if (count($eligible) > 0) {
            $this->logger->info('Eligible issues', [
                'count' => count($eligible),
                'issues' => array_map(fn (array $e) => $e['issue']->identifier.($e['stage'] ? ":{$e['stage']->name}" : ''), $eligible),
            ]);
        }

        // 6. Dispatch
        $availableSlots = $this->config->maxConcurrentAgents() - count($this->running);

        foreach ($eligible as $entry) {
            if ($availableSlots <= 0) {
                break;
            }

            $this->dispatch($entry['issue'], $entry['stage']);
            $availableSlots--;
        }

        // Process retry queue
        $this->processRetryQueue();
    }

    /**
     * @param  Issue[]  $candidates
     * @return array<array{issue: Issue, stage: ?StageConfig}>
     */
    private function filterEligible(array $candidates): array
    {
        $eligible = [];
        $terminalStates = array_map('strtolower', $this->config->trackerTerminalStates());
        $hasPipeline = $this->config->hasPipeline();

        foreach ($candidates as $issue) {
            // Skip if already running
            if (isset($this->running[$issue->id])) {
                continue;
            }

            // Skip if claimed (for pipeline: claimed includes stage suffix)
            if ($hasPipeline) {
                $stage = $this->config->stageForLabels($issue->labels);
                if ($stage === null) {
                    // No matching stage trigger label — skip
                    continue;
                }
                $claimKey = $issue->id.':'.$stage->name;
                if (isset($this->claimed[$claimKey])) {
                    continue;
                }
            } else {
                $stage = null;
                if (isset($this->claimed[$issue->id])) {
                    continue;
                }
            }

            // Skip if in retry queue and not yet due
            if (isset($this->retryQueue[$issue->id])) {
                $retryState = $this->retryQueue[$issue->id];
                if (hrtime(true) < $retryState['dueAt']) {
                    continue;
                }
            }

            // Skip if has non-terminal blockers
            if (! empty($issue->blockedBy)) {
                $blockerStates = $this->tracker->fetchStatesByIds($issue->blockedBy);
                $hasActiveBlocker = false;
                foreach ($blockerStates as $state) {
                    if (! in_array(strtolower($state), $terminalStates, true)) {
                        $hasActiveBlocker = true;
                        break;
                    }
                }
                if ($hasActiveBlocker) {
                    continue;
                }
            }

            $eligible[] = ['issue' => $issue, 'stage' => $stage];
        }

        return $eligible;
    }

    private function dispatch(Issue $issue, ?StageConfig $stage = null): void
    {
        $label = $stage ? "{$issue->identifier}:{$stage->name}" : $issue->identifier;
        $this->console("  <info>Dispatching</info> {$label}: {$issue->title}");
        $this->logger->info('Dispatching issue', [
            'issue_id' => $issue->id,
            'issue_identifier' => $issue->identifier,
            'stage' => $stage?->name,
        ]);

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->logger->error('Failed to fork for issue', [
                'issue_id' => $issue->id,
            ]);

            return;
        }

        if ($pid === 0) {
            // Child process
            $exitCode = $this->runChild($issue, $stage);
            exit($exitCode);
        }

        // Parent process
        $now = hrtime(true);
        $this->running[$issue->id] = [
            'pid' => $pid,
            'issue' => $issue,
            'stage' => $stage,
            'startedAt' => $now,
            'lastActivity' => $now,
        ];

        $claimKey = $stage ? $issue->id.':'.$stage->name : $issue->id;
        $this->claimed[$claimKey] = true;
    }

    private function runChild(Issue $issue, ?StageConfig $stage = null): int
    {
        try {
            // Create workspace
            $workspacePath = $this->workspace->create($issue);

            // Build prompt — use stage-specific prompt if in pipeline mode
            $attempt = $this->retryQueue[$issue->id]['attempt'] ?? null;
            $promptTemplate = $stage?->prompt ?? $this->workflowLoader->load()['prompt'];
            $prompt = $this->promptBuilder->render($promptTemplate, $issue->toArray(), $attempt);

            // Build overrides from stage config
            $overrides = [];
            if ($stage) {
                $overrides = [
                    'command' => $stage->command,
                    'max_turns' => $stage->maxTurns,
                    'turn_timeout_ms' => $stage->turnTimeoutMs,
                    'stall_timeout_ms' => $stage->stallTimeoutMs,
                ];
            }

            // Run agent
            $result = $this->agentRunner->run($prompt, $workspacePath, $overrides);

            $this->logger->info('Agent completed', [
                'issue_id' => $issue->id,
                'issue_identifier' => $issue->identifier,
                'stage' => $stage?->name,
                'success' => $result['success'],
                'tokens' => $result['tokens'],
                'session_id' => $result['session_id'],
            ]);

            return $result['success'] ? 0 : 1;
        } catch (\Throwable $e) {
            $this->logger->error('Child process failed', [
                'issue_id' => $issue->id,
                'stage' => $stage?->name,
                'error' => $e->getMessage(),
            ]);

            return 2;
        }
    }

    private function reconcile(): void
    {
        if (empty($this->running)) {
            return;
        }

        $stallTimeoutMs = $this->config->claudeStallTimeoutMs();
        $nowNs = hrtime(true);
        $finishedIds = [];

        foreach ($this->running as $issueId => $worker) {
            // Check child process status
            $status = pcntl_waitpid($worker['pid'], $childStatus, WNOHANG);

            if ($status > 0) {
                // Child has exited
                $exitCode = pcntl_wexitstatus($childStatus);
                $finishedIds[] = $issueId;

                $issueIdentifier = $worker['issue']->identifier;
                $this->logger->info('Worker finished', [
                    'issue_id' => $issueId,
                    'pid' => $worker['pid'],
                    'exit_code' => $exitCode,
                ]);

                if ($exitCode !== 0) {
                    $this->console("  <comment>Failed</comment> {$issueIdentifier} (exit {$exitCode}), queuing retry");
                    $this->queueRetry($issueId, 'failure', $exitCode);
                } else {
                    $this->console("  <info>Completed</info> {$issueIdentifier}");
                    // Keep claimed so the issue is not re-dispatched.
                    // It stays claimed until the tracker state changes
                    // to terminal, at which point reconciliation clears it.
                }

                continue;
            }

            // Check for stall
            $stallMs = ($nowNs - $worker['lastActivity']) / 1_000_000;
            if ($stallMs > $stallTimeoutMs) {
                $this->logger->warning('Worker stalled, killing', [
                    'issue_id' => $issueId,
                    'pid' => $worker['pid'],
                    'stall_ms' => $stallMs,
                ]);

                posix_kill($worker['pid'], SIGTERM);
                $finishedIds[] = $issueId;
                $this->queueRetry($issueId, 'stall');
            }
        }

        // Remove finished workers
        foreach ($finishedIds as $id) {
            unset($this->running[$id]);
        }

        // Tracker state refresh for remaining running issues
        if (! empty($this->running)) {
            $ids = array_keys($this->running);
            $states = $this->tracker->fetchStatesByIds($ids);
            $terminalStates = array_map('strtolower', $this->config->trackerTerminalStates());

            foreach ($states as $id => $state) {
                if (! isset($this->running[$id])) {
                    continue;
                }

                $stateLower = strtolower($state);

                if (in_array($stateLower, $terminalStates, true)) {
                    // Terminal: kill and cleanup
                    $this->logger->info('Issue moved to terminal state, killing worker', [
                        'issue_id' => $id,
                        'state' => $state,
                    ]);
                    posix_kill($this->running[$id]['pid'], SIGTERM);
                    $this->workspace->remove($this->running[$id]['issue']);
                    $this->unclaimWorker($id);
                    unset($this->running[$id]);
                }
            }
        }
    }

    private function queueRetry(string $issueId, string $reason, int $exitCode = 0): void
    {
        $attempt = ($this->retryQueue[$issueId]['attempt'] ?? 0) + 1;

        if ($reason === 'continuation') {
            $delayMs = 1000;
        } else {
            $delayMs = min(
                10000 * (int) pow(2, $attempt - 1),
                $this->config->maxRetryBackoffMs()
            );
        }

        $dueAt = hrtime(true) + ($delayMs * 1_000_000); // Convert ms to ns

        $this->retryQueue[$issueId] = [
            'attempt' => $attempt,
            'dueAt' => $dueAt,
            'error' => $reason,
        ];

        $this->logger->info('Issue queued for retry', [
            'issue_id' => $issueId,
            'attempt' => $attempt,
            'delay_ms' => $delayMs,
            'reason' => $reason,
        ]);
    }

    private function unclaimWorker(string $issueId): void
    {
        $worker = $this->running[$issueId] ?? null;
        if ($worker && $worker['stage']) {
            unset($this->claimed[$issueId.':'.$worker['stage']->name]);
        } else {
            unset($this->claimed[$issueId]);
        }
    }

    private function processRetryQueue(): void
    {
        $now = hrtime(true);

        foreach ($this->retryQueue as $issueId => $retryState) {
            if ($now >= $retryState['dueAt']) {
                unset($this->retryQueue[$issueId]);
                $this->unclaimByIssueId($issueId);
            }
        }
    }

    private function unclaimByIssueId(string $issueId): void
    {
        // Remove all claims for this issue (both plain and stage-suffixed)
        unset($this->claimed[$issueId]);
        foreach (array_keys($this->claimed) as $key) {
            if (str_starts_with($key, $issueId.':')) {
                unset($this->claimed[$key]);
            }
        }
    }

    private function reloadConfig(): void
    {
        try {
            $this->workflowLoader->load();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to reload workflow config, skipping tick', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function waitForChildren(): void
    {
        while (! empty($this->running)) {
            foreach ($this->running as $issueId => $worker) {
                $status = pcntl_waitpid($worker['pid'], $childStatus, WNOHANG);
                if ($status > 0) {
                    unset($this->running[$issueId]);
                    $this->logger->info('Worker stopped during shutdown', [
                        'issue_id' => $issueId,
                    ]);
                }
            }

            if (! empty($this->running)) {
                usleep(100000); // 100ms
            }
        }
    }

    private function sleepMs(int $ms): void
    {
        $intervalUs = $ms * 1000;
        $slept = 0;
        $step = 100000; // 100ms steps to check shutdown

        while ($slept < $intervalUs && ! $this->shutdown) {
            $remaining = $intervalUs - $slept;
            $sleepTime = min($step, $remaining);
            usleep($sleepTime);
            $slept += $sleepTime;

            // Allow signal handling
            pcntl_signal_dispatch();
        }
    }

    public function getRunning(): array
    {
        return $this->running;
    }

    public function getClaimed(): array
    {
        return $this->claimed;
    }

    public function getRetryQueue(): array
    {
        return $this->retryQueue;
    }

    public function getClaudeTotals(): array
    {
        return $this->claudeTotals;
    }
}
