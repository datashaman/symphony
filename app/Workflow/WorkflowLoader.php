<?php

namespace App\Workflow;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class WorkflowLoader
{
    public function __construct(
        private string $path,
    ) {}

    /**
     * @return array{config: array, prompt: string, stage_prompts: array<string, string>}
     */
    public function load(): array
    {
        if (! is_readable($this->path)) {
            throw new RuntimeException("Workflow file not readable: {$this->path}");
        }

        $content = file_get_contents($this->path);

        if ($content === false) {
            throw new RuntimeException("Failed to read workflow file: {$this->path}");
        }

        return $this->parse($content);
    }

    /**
     * @return array{config: array, prompt: string, stage_prompts: array<string, string>}
     */
    public function parse(string $content): array
    {
        try {
            $data = Yaml::parse($content);
        } catch (ParseException $e) {
            throw new InvalidArgumentException(
                "Invalid YAML in workflow file: {$e->getMessage()}",
                0,
                $e
            );
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException(
                'Invalid workflow format: YAML must be a mapping'
            );
        }

        $hasPipeline = ! empty($data['pipeline']['stages'] ?? []);

        // Extract stage prompts from pipeline stages
        $stagePrompts = [];
        if ($hasPipeline) {
            foreach ($data['pipeline']['stages'] as &$stage) {
                $name = $stage['name'] ?? '';
                $prompt = $stage['prompt'] ?? '';
                if (is_string($prompt) && trim($prompt) !== '') {
                    $stagePrompts[$name] = $prompt;
                }
                unset($stage['prompt']);
            }
            unset($stage);
        }

        // Extract top-level prompt
        $prompt = $data['prompt'] ?? '';
        unset($data['prompt']);

        if (! $hasPipeline && (! is_string($prompt) || trim($prompt) === '')) {
            throw new InvalidArgumentException(
                'Invalid workflow format: empty prompt template'
            );
        }

        return [
            'config' => $data,
            'prompt' => is_string($prompt) ? $prompt : '',
            'stage_prompts' => $stagePrompts,
        ];
    }
}
