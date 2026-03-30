<?php

namespace App\Commands;

use App\Agent\ClaudeCodeRunner;
use App\Config\WorkflowConfig;
use App\Orchestrator\Orchestrator;
use App\Prompt\PromptBuilder;
use App\Tracker\GitHubTracker;
use App\Tracker\JiraTracker;
use App\Tracker\TrackerInterface;
use App\Workflow\WorkflowLoader;
use App\Workspace\WorkspaceManager;
use Illuminate\Support\Facades\Log;
use LaravelZero\Framework\Commands\Command;

class RunCommand extends Command
{
    protected $signature = 'run {workflow=./WORKFLOW.md}';

    protected $description = 'Run the Symphony orchestrator';

    public function handle(): int
    {
        $workflowPath = $this->argument('workflow');

        if (! file_exists($workflowPath)) {
            $this->error("Workflow file not found: {$workflowPath}");

            return 1;
        }

        $logger = Log::getLogger();

        try {
            // Load workflow
            $loader = new WorkflowLoader($workflowPath);
            $workflow = $loader->load();

            // Build config (pass stage prompts for pipeline workflows)
            $config = new WorkflowConfig($workflow['config'], $workflow['stage_prompts'] ?? []);

            // Create tracker
            $tracker = $this->createTracker($config, $logger);

            // Create components
            $workspace = new WorkspaceManager($config, $logger);
            $promptBuilder = new PromptBuilder;
            $agentRunner = new ClaudeCodeRunner($config, $logger, $this->output);

            // Create orchestrator
            $orchestrator = new Orchestrator(
                $config, $tracker, $workspace, $promptBuilder,
                $agentRunner, $loader, $logger, $this->output
            );

            // Register signal handlers
            pcntl_signal(SIGINT, function () use ($orchestrator) {
                Log::info('Received SIGINT');
                $orchestrator->requestShutdown();
            });

            pcntl_signal(SIGTERM, function () use ($orchestrator) {
                Log::info('Received SIGTERM');
                $orchestrator->requestShutdown();
            });

            $this->line('  Press Ctrl+C to stop');

            $this->info("Symphony starting ({$config->trackerKind()} tracker)");
            $this->line("  Workflow: {$workflowPath}");
            if ($config->hasPipeline()) {
                $stageNames = array_map(fn ($s) => $s->name, $config->stages());
                $this->line('  Pipeline: '.implode(' → ', $stageNames));
            }
            $this->line('  Log file: '.getcwd().'/symphony.log');
            Log::info('Symphony starting', [
                'workflow' => $workflowPath,
                'tracker' => $config->trackerKind(),
            ]);

            // Run the orchestrator loop
            $orchestrator->run();

            return 0;
        } catch (\Throwable $e) {
            $this->error("Startup failed: {$e->getMessage()}");
            Log::error('Startup failed', ['error' => $e->getMessage()]);

            return 1;
        }
    }

    private function createTracker(WorkflowConfig $config, $logger): TrackerInterface
    {
        return match ($config->trackerKind()) {
            'github' => new GitHubTracker($config, $logger),
            'jira' => new JiraTracker($config, $logger),
            default => throw new \InvalidArgumentException(
                "Unsupported tracker kind: {$config->trackerKind()}"
            ),
        };
    }
}
