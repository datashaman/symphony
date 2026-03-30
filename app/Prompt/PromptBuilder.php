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
        $this->twig = new Environment(new ArrayLoader, [
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

        return $rendered;
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
