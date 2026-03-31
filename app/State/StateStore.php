<?php

declare(strict_types=1);

namespace App\State;

use App\Config\StageConfig;
use App\Tracker\Issue;
use PDO;

class StateStore
{
    private PDO $db;

    public function __construct(?string $dbPath = null)
    {
        $dbPath ??= $this->defaultPath();
        $dir = dirname($dbPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->db = new PDO("sqlite:{$dbPath}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->migrate();
    }

    private function defaultPath(): string
    {
        $root = getcwd();

        return $root.'/.symphony/state.sqlite';
    }

    private function migrate(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS daemon (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                pid INTEGER,
                status TEXT NOT NULL DEFAULT "stopped",
                started_at TEXT,
                updated_at TEXT,
                workflow_path TEXT
            )
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS agents (
                issue_id TEXT PRIMARY KEY,
                pid INTEGER NOT NULL,
                issue_identifier TEXT NOT NULL,
                issue_title TEXT NOT NULL,
                issue_url TEXT NOT NULL,
                stage TEXT,
                started_at INTEGER NOT NULL
            )
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS claimed (
                claim_key TEXT PRIMARY KEY
            )
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS retry_queue (
                issue_id TEXT PRIMARY KEY,
                attempt INTEGER NOT NULL,
                due_at INTEGER NOT NULL,
                error TEXT NOT NULL
            )
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS token_totals (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                input_tokens INTEGER NOT NULL DEFAULT 0,
                output_tokens INTEGER NOT NULL DEFAULT 0,
                seconds REAL NOT NULL DEFAULT 0
            )
        ');

        // Seed singleton rows if absent
        $this->db->exec("INSERT OR IGNORE INTO daemon (id, status) VALUES (1, 'stopped')");
        $this->db->exec('INSERT OR IGNORE INTO token_totals (id, input_tokens, output_tokens, seconds) VALUES (1, 0, 0, 0)');
    }

    // --- Daemon lifecycle ---

    public function markRunning(int $pid, string $workflowPath): void
    {
        $now = date('c');
        $this->db->prepare(
            'UPDATE daemon SET pid = ?, status = "running", started_at = ?, updated_at = ?, workflow_path = ? WHERE id = 1'
        )->execute([$pid, $now, $now, $workflowPath]);
    }

    public function markStopped(): void
    {
        $this->db->prepare(
            'UPDATE daemon SET status = "stopped", updated_at = ? WHERE id = 1'
        )->execute([date('c')]);
    }

    public function heartbeat(): void
    {
        $this->db->prepare(
            'UPDATE daemon SET updated_at = ? WHERE id = 1'
        )->execute([date('c')]);
    }

    public function getDaemonStatus(): array
    {
        return $this->db->query('SELECT * FROM daemon WHERE id = 1')->fetch() ?: [
            'status' => 'stopped',
            'pid' => null,
        ];
    }

    // --- Agent state ---

    /**
     * @param  array<string, array{pid: int, issue: Issue, stage: ?StageConfig, startedAt: int}>  $running
     */
    public function syncAgents(array $running): void
    {
        $this->db->exec('DELETE FROM agents');
        $stmt = $this->db->prepare(
            'INSERT INTO agents (issue_id, pid, issue_identifier, issue_title, issue_url, stage, started_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($running as $issueId => $worker) {
            $stmt->execute([
                $issueId,
                $worker['pid'],
                $worker['issue']->identifier,
                $worker['issue']->title,
                $worker['issue']->url,
                $worker['stage']?->name,
                $worker['startedAt'],
            ]);
        }
    }

    public function getAgents(): array
    {
        return $this->db->query('SELECT * FROM agents ORDER BY started_at ASC')->fetchAll();
    }

    // --- Claimed ---

    /**
     * @param  array<string, true>  $claimed
     */
    public function syncClaimed(array $claimed): void
    {
        $this->db->exec('DELETE FROM claimed');
        $stmt = $this->db->prepare('INSERT INTO claimed (claim_key) VALUES (?)');
        foreach (array_keys($claimed) as $key) {
            $stmt->execute([$key]);
        }
    }

    public function getClaimed(): array
    {
        return array_column(
            $this->db->query('SELECT claim_key FROM claimed')->fetchAll(),
            'claim_key'
        );
    }

    // --- Retry queue ---

    /**
     * @param  array<string, array{attempt: int, dueAt: int, error: string}>  $retryQueue
     */
    public function syncRetryQueue(array $retryQueue): void
    {
        $this->db->exec('DELETE FROM retry_queue');
        $stmt = $this->db->prepare(
            'INSERT INTO retry_queue (issue_id, attempt, due_at, error) VALUES (?, ?, ?, ?)'
        );
        foreach ($retryQueue as $issueId => $entry) {
            $stmt->execute([
                $issueId,
                $entry['attempt'],
                $entry['dueAt'],
                $entry['error'],
            ]);
        }
    }

    public function getRetryQueue(): array
    {
        return $this->db->query('SELECT * FROM retry_queue ORDER BY due_at ASC')->fetchAll();
    }

    // --- Token totals ---

    /**
     * @param  array{input_tokens: int, output_tokens: int, seconds: float}  $totals
     */
    public function syncTokenTotals(array $totals): void
    {
        $this->db->prepare(
            'UPDATE token_totals SET input_tokens = ?, output_tokens = ?, seconds = ? WHERE id = 1'
        )->execute([
            $totals['input_tokens'],
            $totals['output_tokens'],
            $totals['seconds'],
        ]);
    }

    public function getTokenTotals(): array
    {
        return $this->db->query('SELECT input_tokens, output_tokens, seconds FROM token_totals WHERE id = 1')->fetch() ?: [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'seconds' => 0,
        ];
    }

    // --- Flush all orchestrator state in one call ---

    public function flush(array $running, array $claimed, array $retryQueue, array $claudeTotals): void
    {
        $this->db->beginTransaction();
        try {
            $this->syncAgents($running);
            $this->syncClaimed($claimed);
            $this->syncRetryQueue($retryQueue);
            $this->syncTokenTotals($claudeTotals);
            $this->heartbeat();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
