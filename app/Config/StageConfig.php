<?php

namespace App\Config;

use InvalidArgumentException;

class StageConfig
{
    public readonly string $name;

    public readonly string $trigger;

    public readonly string $command;

    public readonly int $maxTurns;

    public readonly int $turnTimeoutMs;

    public readonly int $stallTimeoutMs;

    public readonly string $prompt;

    public function __construct(array $stage, array $claudeDefaults, string $prompt)
    {
        if (empty($stage['name'])) {
            throw new InvalidArgumentException('Each pipeline stage must have a name');
        }

        if (empty($stage['trigger'])) {
            throw new InvalidArgumentException("Pipeline stage '{$stage['name']}' must have a trigger label");
        }

        $this->name = $stage['name'];
        $this->trigger = strtolower($stage['trigger']);
        $this->command = $stage['command'] ?? $claudeDefaults['command'];
        $this->maxTurns = $stage['max_turns'] ?? $claudeDefaults['max_turns'] ?? 20;
        $this->turnTimeoutMs = $stage['turn_timeout_ms'] ?? $claudeDefaults['turn_timeout_ms'];
        $this->stallTimeoutMs = $stage['stall_timeout_ms'] ?? $claudeDefaults['stall_timeout_ms'];
        $this->prompt = $prompt;
    }
}
