<?php

namespace App\Prompt;

use DateTimeInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class PromptBuilder
{
    private Environment $twig;

    public function __construct()
    {
        $this->twig = new Environment(new ArrayLoader(), [
            'strict_variables' => true,
            'autoescape' => false,
        ]);
    }

    public function render(string $template, array $issue, ?int $attempt = null): string
    {
        $issueArray = $this->convertDateTimes($issue);

        $twigTemplate = $this->twig->createTemplate($template);

        $rendered = $twigTemplate->render([
            'issue' => $issueArray,
            'attempt' => $attempt,
        ]);

        return $rendered . "\n\n" . $this->primeDirective($issueArray);
    }

    private function primeDirective(array $issue): string
    {
        $identifier = $issue['identifier'] ?? '';
        $url = $issue['url'] ?? '';

        $closing = $url !== '' ? "Closes {$url}" : "Closes {$identifier}";

        return <<<DIRECTIVE
        ## Prime Directive

        After completing all work, you MUST commit, push, and open a pull request. This is the entire point of your task — unshipped work has zero value.

        1. **Commit** all changes with a clear, descriptive commit message referencing {$identifier}.
        2. **Push** the current branch to the remote.
        3. **Create a pull request** with:
           - A concise title summarizing the change
           - A body that describes what was done and includes "{$closing}"
           - Target the repository's default branch (main/master)

        Do NOT stop after making code changes. The pull request is your deliverable.
        DIRECTIVE;
    }

    private function convertDateTimes(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $data[$key] = $value->format('c');
            } elseif (is_array($value)) {
                $data[$key] = $this->convertDateTimes($value);
            }
        }

        return $data;
    }
}
