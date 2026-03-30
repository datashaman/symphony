<?php

namespace App\Agent;

use App\Config\WorkflowConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ClaudeCodeRunner
{
    public function __construct(
        private WorkflowConfig $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * Run a single turn of the Claude Code agent.
     *
     * @return array{success: bool, tokens: array{input_tokens: int, output_tokens: int}, session_id: string|null}
     */
    public function runTurn(string $prompt, string $workspacePath, bool $isContinuation = false): array
    {
        $command = $this->config->claudeCommand();

        if ($isContinuation) {
            $command .= ' --continue';
        }

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes, $workspacePath);

        if (!is_resource($process)) {
            throw new RuntimeException("Failed to launch Claude Code: {$command}");
        }

        // Write prompt and close stdin
        if (!$isContinuation) {
            fwrite($pipes[0], $prompt);
        }
        fclose($pipes[0]);

        // Set stdout and stderr to non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $result = [
            'success' => false,
            'tokens' => ['input_tokens' => 0, 'output_tokens' => 0],
            'session_id' => null,
        ];

        $startTime = hrtime(true);
        $lastActivity = hrtime(true);
        $turnTimeoutMs = $this->config->claudeTurnTimeoutMs();
        $stallTimeoutMs = $this->config->claudeStallTimeoutMs();
        $stdout = '';
        $stderr = '';

        while (true) {
            $status = proc_get_status($process);

            // Read available stdout
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false && $chunk !== '') {
                $stdout .= $chunk;
                $lastActivity = hrtime(true);

                // Process complete JSON lines
                $this->processStreamOutput($stdout, $result);
            }

            // Read available stderr
            $errChunk = stream_get_contents($pipes[2]);
            if ($errChunk !== false && $errChunk !== '') {
                $stderr .= $errChunk;
            }

            if (!$status['running']) {
                // Read remaining
                $remaining = stream_get_contents($pipes[1]);
                if ($remaining) {
                    $stdout .= $remaining;
                    $this->processStreamOutput($stdout, $result);
                }
                $stderr .= stream_get_contents($pipes[2]);

                $result['success'] = $status['exitcode'] === 0;
                break;
            }

            $nowNs = hrtime(true);

            // Check turn timeout
            $elapsedMs = ($nowNs - $startTime) / 1_000_000;
            if ($elapsedMs > $turnTimeoutMs) {
                $this->killProcess($process, $pipes);
                $this->logger->error('Turn timeout reached', [
                    'elapsed_ms' => $elapsedMs,
                    'timeout_ms' => $turnTimeoutMs,
                ]);

                return $result;
            }

            // Check stall timeout
            $stallMs = ($nowNs - $lastActivity) / 1_000_000;
            if ($stallMs > $stallTimeoutMs) {
                $this->killProcess($process, $pipes);
                $this->logger->error('Stall timeout reached', [
                    'stall_ms' => $stallMs,
                    'timeout_ms' => $stallTimeoutMs,
                ]);

                return $result;
            }

            usleep(50000); // 50ms
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($stderr) {
            $this->logger->debug('Claude Code stderr', ['stderr' => $stderr]);
        }

        return $result;
    }

    /**
     * Run multi-turn agent session.
     *
     * @return array{success: bool, tokens: array{input_tokens: int, output_tokens: int}, session_id: string|null}
     */
    public function run(string $prompt, string $workspacePath): array
    {
        $maxTurns = $this->config->maxTurns();

        $totalTokens = ['input_tokens' => 0, 'output_tokens' => 0];
        $sessionId = null;
        $success = false;

        for ($turn = 1; $turn <= $maxTurns; $turn++) {
            $isContinuation = $turn > 1;

            $this->logger->info('Starting agent turn', [
                'turn' => $turn,
                'max_turns' => $maxTurns,
                'continuation' => $isContinuation,
            ]);

            $result = $this->runTurn($prompt, $workspacePath, $isContinuation);

            $totalTokens['input_tokens'] += $result['tokens']['input_tokens'];
            $totalTokens['output_tokens'] += $result['tokens']['output_tokens'];

            if ($result['session_id']) {
                $sessionId = $result['session_id'];
            }

            $success = $result['success'];

            if ($success) {
                break;
            }

            // If not successful and not the last turn, continue with a short delay
            if ($turn < $maxTurns) {
                usleep(1000000); // 1000ms continuation delay
            }
        }

        return [
            'success' => $success,
            'tokens' => $totalTokens,
            'session_id' => $sessionId,
        ];
    }

    private function processStreamOutput(string &$buffer, array &$result): void
    {
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $data = json_decode($line, true);
            if ($data === null) {
                continue;
            }

            // Extract session_id
            if (isset($data['session_id'])) {
                $result['session_id'] = $data['session_id'];
            }

            // Accumulate token usage
            if (isset($data['usage'])) {
                $result['tokens']['input_tokens'] += $data['usage']['input_tokens'] ?? 0;
                $result['tokens']['output_tokens'] += $data['usage']['output_tokens'] ?? 0;
            }

            // Also check for tokens at top level
            if (isset($data['input_tokens'])) {
                $result['tokens']['input_tokens'] += $data['input_tokens'];
            }
            if (isset($data['output_tokens'])) {
                $result['tokens']['output_tokens'] += $data['output_tokens'];
            }

            // Log unknown types at debug
            $type = $data['type'] ?? null;
            $knownTypes = [
                'message', 'content_block_delta', 'content_block_stop',
                'message_delta', 'message_stop', 'result', 'system',
                'assistant', 'user', 'rate_limit_event',
            ];
            if ($type && !in_array($type, $knownTypes)) {
                $this->logger->warning("Unhandled stream event type: {$type}", ['data' => $data]);
            }
        }
    }

    private function killProcess($process, array $pipes): void
    {
        proc_terminate($process, 15); // SIGTERM

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_close($process);
    }
}
