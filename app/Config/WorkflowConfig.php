<?php

namespace App\Config;

use InvalidArgumentException;

class WorkflowConfig
{
    private const SUPPORTED_TRACKERS = ['github', 'jira'];

    private const DEFAULTS = [
        'tracker' => [
            'active_states' => ['Todo', 'In Progress'],
            'terminal_states' => ['Closed', 'Cancelled', 'Canceled', 'Duplicate', 'Done'],
        ],
        'polling' => [
            'interval_ms' => 30000,
        ],
        'agent' => [
            'max_concurrent_agents' => 10,
            'max_turns' => 20,
            'max_retry_backoff_ms' => 300000,
        ],
        'claude' => [
            'command' => 'claude -p --verbose --output-format stream-json --dangerously-skip-permissions',
            'turn_timeout_ms' => 3600000,
            'stall_timeout_ms' => 300000,
        ],
    ];

    private array $resolved;

    public function __construct(array $raw)
    {
        $this->resolved = $this->resolveEnvVars($this->mergeDefaults($raw));
        $this->validate();
    }

    public function trackerKind(): string
    {
        return $this->resolved['tracker']['kind'];
    }

    public function trackerApiKey(): string
    {
        return $this->resolved['tracker']['api_key'];
    }

    public function trackerRepository(): ?string
    {
        return $this->resolved['tracker']['repository'] ?? null;
    }

    public function trackerEndpoint(): ?string
    {
        return $this->resolved['tracker']['endpoint'] ?? null;
    }

    public function trackerEmail(): ?string
    {
        return $this->resolved['tracker']['email'] ?? null;
    }

    public function trackerProjectSlug(): ?string
    {
        return $this->resolved['tracker']['project_slug'] ?? null;
    }

    public function trackerAssignee(): string
    {
        return $this->resolved['tracker']['assignee'] ?? 'currentUser()';
    }

    public function trackerSprint(): string
    {
        return $this->resolved['tracker']['sprint'] ?? 'openSprints()';
    }

    public function trackerJql(): ?string
    {
        return $this->resolved['tracker']['jql'] ?? null;
    }

    public function trackerActiveStates(): array
    {
        return $this->resolved['tracker']['active_states'];
    }

    public function trackerTerminalStates(): array
    {
        return $this->resolved['tracker']['terminal_states'];
    }

    public function pollingIntervalMs(): int
    {
        return $this->resolved['polling']['interval_ms'];
    }

    public function workspaceRoot(): string
    {
        return $this->resolved['workspace']['root'] ?? '';
    }

    public function workspaceSetup(): array
    {
        return (array) ($this->resolved['workspace']['setup'] ?? []);
    }

    public function setupTimeoutMs(): int
    {
        return $this->resolved['workspace']['setup_timeout_ms'] ?? 60000;
    }

    public function maxConcurrentAgents(): int
    {
        return $this->resolved['agent']['max_concurrent_agents'];
    }

    public function maxTurns(): int
    {
        return $this->resolved['agent']['max_turns'];
    }

    public function maxRetryBackoffMs(): int
    {
        return $this->resolved['agent']['max_retry_backoff_ms'];
    }

    public function claudeCommand(): string
    {
        return $this->resolved['claude']['command'];
    }

    public function claudeTurnTimeoutMs(): int
    {
        return $this->resolved['claude']['turn_timeout_ms'];
    }

    public function claudeStallTimeoutMs(): int
    {
        return $this->resolved['claude']['stall_timeout_ms'];
    }

    public function toArray(): array
    {
        return $this->resolved;
    }

    private function mergeDefaults(array $raw): array
    {
        return array_replace_recursive(self::DEFAULTS, $raw);
    }

    private function resolveEnvVars(array $config): array
    {
        array_walk_recursive($config, function (&$value) {
            if (!is_string($value)) {
                return;
            }

            $value = preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_]*)\}|\$([A-Za-z_][A-Za-z0-9_]*)/', function ($matches) {
                $varName = $matches[1] !== '' ? $matches[1] : $matches[2];
                $envValue = getenv($varName);

                if ($envValue === false) {
                    throw new InvalidArgumentException(
                        "Environment variable '{$varName}' is not set"
                    );
                }

                return $envValue;
            }, $value);
        });

        return $config;
    }

    private function validate(): void
    {
        if (!isset($this->resolved['tracker']['kind'])) {
            throw new InvalidArgumentException('Missing required config: tracker.kind');
        }

        if (!in_array($this->resolved['tracker']['kind'], self::SUPPORTED_TRACKERS, true)) {
            throw new InvalidArgumentException(
                "Unsupported tracker kind '{$this->resolved['tracker']['kind']}'. Supported: " . implode(', ', self::SUPPORTED_TRACKERS)
            );
        }

        if (empty($this->resolved['tracker']['api_key'])) {
            throw new InvalidArgumentException('Missing required config: tracker.api_key');
        }

    }
}
