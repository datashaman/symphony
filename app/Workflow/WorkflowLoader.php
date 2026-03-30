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
     * @return array{config: array, prompt: string}
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
        // Match YAML front matter between --- delimiters
        if (! preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n(.*)\z/s', $content, $matches)) {
            throw new InvalidArgumentException(
                'Invalid workflow format: missing YAML front matter delimiters (---)'
            );
        }

        $yamlContent = $matches[1];
        $body = $matches[2];

        try {
            $config = Yaml::parse($yamlContent);
        } catch (ParseException $e) {
            throw new InvalidArgumentException(
                "Invalid YAML in workflow front matter: {$e->getMessage()}",
                0,
                $e
            );
        }

        if (! is_array($config)) {
            throw new InvalidArgumentException(
                'Invalid workflow format: YAML front matter must be a mapping'
            );
        }

        // Parse stage-specific prompts from the body
        $stagePrompts = $this->parseStagePrompts($body);
        $prompt = $stagePrompts['_default'] ?? '';
        unset($stagePrompts['_default']);

        $hasPipeline = ! empty($config['pipeline']['stages'] ?? []);

        if (! $hasPipeline && trim($prompt) === '') {
            throw new InvalidArgumentException(
                'Invalid workflow format: empty prompt template'
            );
        }

        return [
            'config' => $config,
            'prompt' => $prompt,
            'stage_prompts' => $stagePrompts,
        ];
    }

    /**
     * Parse body into default prompt and named stage prompts.
     *
     * Stage prompts are delimited by ---stage:name--- markers.
     * Content before the first marker is the default prompt.
     *
     * @return array<string, string> Keys are stage names (or '_default')
     */
    private function parseStagePrompts(string $body): array
    {
        $parts = preg_split('/^---stage:(\w+)---\s*$/m', $body, -1, PREG_SPLIT_DELIM_CAPTURE);

        $prompts = [];

        // First part is the default prompt (content before any stage marker)
        $default = trim($parts[0] ?? '');
        if ($default !== '') {
            $prompts['_default'] = $parts[0];
        }

        // Remaining parts alternate: stage name, stage content
        for ($i = 1; $i < count($parts); $i += 2) {
            $stageName = $parts[$i];
            $stageContent = $parts[$i + 1] ?? '';
            $trimmed = trim($stageContent);
            if ($trimmed !== '') {
                $prompts[$stageName] = $stageContent;
            }
        }

        return $prompts;
    }
}
