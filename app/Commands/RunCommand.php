<?php

namespace App\Commands;

use App\Agent\ClaudeCodeRunner;
use App\Config\WorkflowConfig;
use App\Logging\StructuredFormatter;
use App\Orchestrator\Orchestrator;
use App\Prompt\PromptBuilder;
use App\Tracker\GitHubTracker;
use App\Tracker\JiraTracker;
use App\Tracker\TrackerInterface;
use App\Workflow\WorkflowLoader;
use App\Workspace\WorkspaceManager;
use LaravelZero\Framework\Commands\Command;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class RunCommand extends Command
{
    protected $signature = 'run {workflow=./WORKFLOW.md}';
    protected $description = 'Run the Symphony orchestrator';

    public function handle(): int
    {
        $workflowPath = $this->argument('workflow');

        if (!file_exists($workflowPath)) {
            $this->error("Workflow file not found: {$workflowPath}");

            return 1;
        }

        // Set up structured logger
        $logger = new Logger('symphony');
        $handler = new StreamHandler('php://stderr', Logger::INFO);
        $handler->setFormatter(new StructuredFormatter());
        $logger->pushHandler($handler);

        try {
            // Load workflow
            $loader = new WorkflowLoader($workflowPath);
            $workflow = $loader->load();

            // Build config
            $config = new WorkflowConfig($workflow['config']);

            // Create tracker
            $tracker = $this->createTracker($config, $logger);

            // Create components
            $workspace = new WorkspaceManager($config, $logger);
            $promptBuilder = new PromptBuilder();
            $agentRunner = new ClaudeCodeRunner($config, $logger);

            // Create orchestrator
            $orchestrator = new Orchestrator(
                $config, $tracker, $workspace, $promptBuilder,
                $agentRunner, $loader, $logger
            );

            // Register signal handlers
            pcntl_signal(SIGINT, function () use ($orchestrator, $logger) {
                $logger->info('Received SIGINT');
                $orchestrator->requestShutdown();
            });

            pcntl_signal(SIGTERM, function () use ($orchestrator, $logger) {
                $logger->info('Received SIGTERM');
                $orchestrator->requestShutdown();
            });

            $logger->info('Symphony starting', [
                'workflow' => $workflowPath,
                'tracker' => $config->trackerKind(),
            ]);

            // Run the orchestrator loop
            $orchestrator->run();

            return 0;
        } catch (\Throwable $e) {
            $this->error("Startup failed: {$e->getMessage()}");
            $logger->error('Startup failed', ['error' => $e->getMessage()]);

            return 1;
        }
    }

    private function createTracker(WorkflowConfig $config, Logger $logger): TrackerInterface
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
