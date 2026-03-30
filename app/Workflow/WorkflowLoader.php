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
        if (!is_readable($this->path)) {
            throw new RuntimeException("Workflow file not readable: {$this->path}");
        }

        $content = file_get_contents($this->path);

        if ($content === false) {
            throw new RuntimeException("Failed to read workflow file: {$this->path}");
        }

        return $this->parse($content);
    }

    /**
     * @return array{config: array, prompt: string}
     */
    public function parse(string $content): array
    {
        // Match YAML front matter between --- delimiters
        if (!preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n(.*)\z/s', $content, $matches)) {
            throw new InvalidArgumentException(
                'Invalid workflow format: missing YAML front matter delimiters (---)'
            );
        }

        $yamlContent = $matches[1];
        $prompt = $matches[2];

        if (trim($prompt) === '') {
            throw new InvalidArgumentException(
                'Invalid workflow format: empty prompt template'
            );
        }

        try {
            $config = Yaml::parse($yamlContent);
        } catch (ParseException $e) {
            throw new InvalidArgumentException(
                "Invalid YAML in workflow front matter: {$e->getMessage()}",
                0,
                $e
            );
        }

        if (!is_array($config)) {
            throw new InvalidArgumentException(
                'Invalid workflow format: YAML front matter must be a mapping'
            );
        }

        return [
            'config' => $config,
            'prompt' => $prompt,
        ];
    }
}
