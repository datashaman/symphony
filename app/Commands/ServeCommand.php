<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class ServeCommand extends Command
{
    protected $signature = 'serve {--host=127.0.0.1 : The host address} {--port=8085 : The port} {--state-db= : Path to state SQLite database}';

    protected $description = 'Start the Symphony web UI server';

    public function handle(): int
    {
        $host = $this->option('host');
        $port = $this->option('port');
        $publicPath = $this->getPublicPath();

        if (! is_dir($publicPath)) {
            $this->error("Public directory not found: {$publicPath}");

            return 1;
        }

        $stateDb = $this->option('state-db') ?: getcwd().'/.symphony/state.sqlite';

        $this->info("Symphony Web UI starting on http://{$host}:{$port}");
        $this->line("  State database: {$stateDb}");
        $this->line("  Document root:  {$publicPath}");
        $this->line('  Press Ctrl+C to stop');

        $env = [
            'SYMPHONY_STATE_DB' => $stateDb,
            'SYMPHONY_LOG_PATH' => $this->getLogPath(),
            'SYMPHONY_WORKFLOW_PATH' => getcwd().'/workflow.yml',
        ];

        $envFlags = '';
        foreach ($env as $key => $value) {
            $envFlags .= " -d {$key}={$value}";
        }

        $command = sprintf(
            'php -S %s:%s -t %s %s/router.php%s',
            $host,
            $port,
            escapeshellarg($publicPath),
            escapeshellarg($publicPath),
            $envFlags
        );

        passthru($command, $exitCode);

        return $exitCode;
    }

    private function getPublicPath(): string
    {
        // When running as phar, extract path differently
        if (\Phar::running()) {
            return dirname(\Phar::running(false)).'/public';
        }

        return base_path('public');
    }

    private function getLogPath(): string
    {
        if (\Phar::running()) {
            return dirname(\Phar::running(false)).'/symphony.log';
        }

        return getcwd().'/symphony.log';
    }
}
