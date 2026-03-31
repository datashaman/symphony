<?php

declare(strict_types=1);

/**
 * Lightweight API handler for the Symphony Web UI.
 *
 * Runs inside PHP's built-in web server (no framework bootstrap needed).
 * Reads state from the shared SQLite database written by the Orchestrator.
 */

// Autoload is needed for StateStore
require_once __DIR__.'/../../vendor/autoload.php';

use App\State\StateStore;
use Symfony\Component\Yaml\Yaml;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

$stateDbPath = ini_get('SYMPHONY_STATE_DB') ?: (getcwd().'/.symphony/state.sqlite');
$logPath = ini_get('SYMPHONY_LOG_PATH') ?: (getcwd().'/symphony.log');
$workflowPath = ini_get('SYMPHONY_WORKFLOW_PATH') ?: (getcwd().'/workflow.yml');

try {
    $store = new StateStore($stateDbPath);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => 'State database not available: '.$e->getMessage()]);
    exit;
}

try {
    match (true) {
        $uri === '/api/status' && $method === 'GET' => handleStatus($store),
        $uri === '/api/agents' && $method === 'GET' => handleAgents($store),
        $uri === '/api/queue' && $method === 'GET' => handleQueue($store),
        $uri === '/api/tokens' && $method === 'GET' => handleTokens($store),
        $uri === '/api/logs' && $method === 'GET' => handleLogs($logPath),
        $uri === '/api/config' && $method === 'GET' => handleConfigGet($workflowPath),
        $uri === '/api/config' && $method === 'PUT' => handleConfigPut($workflowPath),
        $uri === '/api/control' && $method === 'POST' => handleControl($store, $workflowPath),
        default => notFound(),
    };
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleStatus(StateStore $store): void
{
    $daemon = $store->getDaemonStatus();
    $agents = $store->getAgents();

    // Check if daemon is actually alive (heartbeat within last 120s)
    $isAlive = false;
    if ($daemon['status'] === 'running' && $daemon['pid']) {
        $isAlive = posix_kill((int) $daemon['pid'], 0);
    }

    echo json_encode([
        'status' => $isAlive ? 'running' : 'stopped',
        'pid' => $daemon['pid'],
        'started_at' => $daemon['started_at'],
        'updated_at' => $daemon['updated_at'],
        'workflow_path' => $daemon['workflow_path'],
        'agent_count' => count($agents),
    ]);
}

function handleAgents(StateStore $store): void
{
    $agents = $store->getAgents();
    $now = hrtime(true);

    foreach ($agents as &$agent) {
        $elapsed = ($now - (int) $agent['started_at']) / 1_000_000_000;
        $agent['elapsed_seconds'] = round($elapsed, 1);
    }

    echo json_encode(['agents' => $agents]);
}

function handleQueue(StateStore $store): void
{
    $claimed = $store->getClaimed();
    $retryQueue = $store->getRetryQueue();
    $now = hrtime(true);

    foreach ($retryQueue as &$entry) {
        $remaining = ((int) $entry['due_at'] - $now) / 1_000_000_000;
        $entry['remaining_seconds'] = max(0, round($remaining, 1));
    }

    echo json_encode([
        'claimed' => $claimed,
        'retry_queue' => $retryQueue,
    ]);
}

function handleTokens(StateStore $store): void
{
    $totals = $store->getTokenTotals();

    // Estimate cost: Claude Opus pricing (approximate)
    $inputCost = ((int) $totals['input_tokens'] / 1_000_000) * 15.0;
    $outputCost = ((int) $totals['output_tokens'] / 1_000_000) * 75.0;

    echo json_encode([
        'input_tokens' => (int) $totals['input_tokens'],
        'output_tokens' => (int) $totals['output_tokens'],
        'total_tokens' => (int) $totals['input_tokens'] + (int) $totals['output_tokens'],
        'seconds' => (float) $totals['seconds'],
        'estimated_cost_usd' => round($inputCost + $outputCost, 4),
    ]);
}

function handleLogs(string $logPath): void
{
    $lines = (int) ($_GET['lines'] ?? 100);
    $since = $_GET['since'] ?? null;
    $lines = min($lines, 1000);

    if (! file_exists($logPath)) {
        echo json_encode(['logs' => [], 'file' => $logPath, 'exists' => false]);

        return;
    }

    $output = [];
    $exitCode = 0;
    exec(sprintf('tail -n %d %s', $lines, escapeshellarg($logPath)), $output, $exitCode);

    $logs = [];
    foreach ($output as $line) {
        if ($since !== null) {
            // Basic timestamp filtering: structured logs start with timestamp=...
            if (preg_match('/^timestamp=(\S+)/', $line, $m)) {
                if (strcmp($m[1], $since) <= 0) {
                    continue;
                }
            }
        }
        $logs[] = $line;
    }

    $lastTimestamp = null;
    if (! empty($logs)) {
        $lastLine = end($logs);
        if (preg_match('/^timestamp=(\S+)/', $lastLine, $m)) {
            $lastTimestamp = $m[1];
        }
    }

    echo json_encode([
        'logs' => $logs,
        'count' => count($logs),
        'last_timestamp' => $lastTimestamp,
    ]);
}

function handleConfigGet(string $workflowPath): void
{
    if (! file_exists($workflowPath)) {
        echo json_encode(['exists' => false, 'path' => $workflowPath, 'content' => '']);

        return;
    }

    echo json_encode([
        'exists' => true,
        'path' => $workflowPath,
        'content' => file_get_contents($workflowPath),
    ]);
}

function handleConfigPut(string $workflowPath): void
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (! isset($input['content'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing "content" field']);

        return;
    }

    // Validate YAML syntax before saving
    try {
        Yaml::parse($input['content']);
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid YAML: '.$e->getMessage()]);

        return;
    }

    // Backup current file
    if (file_exists($workflowPath)) {
        copy($workflowPath, $workflowPath.'.bak');
    }

    file_put_contents($workflowPath, $input['content']);

    echo json_encode(['saved' => true, 'path' => $workflowPath]);
}

function handleControl(StateStore $store, string $workflowPath): void
{
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    match ($action) {
        'stop' => controlStop($store),
        'start' => controlStart($workflowPath),
        default => (function () use ($action) {
            http_response_code(400);
            echo json_encode(['error' => "Unknown action: {$action}"]);
        })(),
    };
}

function controlStop(StateStore $store): void
{
    $daemon = $store->getDaemonStatus();
    if (! $daemon['pid'] || $daemon['status'] !== 'running') {
        http_response_code(409);
        echo json_encode(['error' => 'Daemon is not running']);

        return;
    }

    $pid = (int) $daemon['pid'];
    if (! posix_kill($pid, 0)) {
        $store->markStopped();
        http_response_code(409);
        echo json_encode(['error' => 'Daemon process not found, marking as stopped']);

        return;
    }

    posix_kill($pid, SIGTERM);
    echo json_encode(['sent' => 'SIGTERM', 'pid' => $pid]);
}

function controlStart(string $workflowPath): void
{
    if (! file_exists($workflowPath)) {
        http_response_code(404);
        echo json_encode(['error' => "Workflow file not found: {$workflowPath}"]);

        return;
    }

    // Determine the application path
    $appPath = realpath(__DIR__.'/../../application');
    if (! $appPath) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot locate application binary']);

        return;
    }

    $cmd = sprintf(
        'nohup php %s run %s > /dev/null 2>&1 & echo $!',
        escapeshellarg($appPath),
        escapeshellarg($workflowPath)
    );

    $pid = trim((string) shell_exec($cmd));

    echo json_encode(['started' => true, 'pid' => (int) $pid]);
}

function notFound(): void
{
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}
