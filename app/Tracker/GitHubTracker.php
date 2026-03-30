<?php

namespace App\Tracker;

use App\Config\WorkflowConfig;
use DateTimeImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;

class GitHubTracker implements TrackerInterface
{
    private PendingRequest $http;
    private string $baseUrl = 'https://api.github.com';
    private string $owner;
    private string $repo;
    private array $activeStates;
    private array $terminalStates;

    public function __construct(
        private WorkflowConfig $config,
        private LoggerInterface $logger,
        ?PendingRequest $http = null,
    ) {
        $repository = $config->trackerRepository();
        if (!$repository || !str_contains($repository, '/')) {
            throw new \InvalidArgumentException('GitHub tracker requires tracker.repository in owner/repo format');
        }

        [$this->owner, $this->repo] = explode('/', $repository, 2);
        $this->activeStates = array_map('strtolower', $config->trackerActiveStates());
        $this->terminalStates = array_map('strtolower', $config->trackerTerminalStates());

        $this->http = $http ?? Http::withToken($config->trackerApiKey())
            ->withHeaders([
                'Accept' => 'application/vnd.github.v3+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->throw();
    }

    public function fetchCandidateIssues(): array
    {
        $allIssues = $this->fetchIssuesWithPagination("{$this->baseUrl}/repos/{$this->owner}/{$this->repo}/issues", [
            'state' => 'open',
            'per_page' => 100,
        ]);

        return array_values(array_filter($allIssues, fn(Issue $issue) =>
            in_array(strtolower($issue->state), $this->activeStates, true)
        ));
    }

    public function fetchIssuesByStates(array $states): array
    {
        $states = array_map('strtolower', $states);
        $allIssues = $this->fetchIssuesWithPagination("{$this->baseUrl}/repos/{$this->owner}/{$this->repo}/issues", [
            'state' => 'open',
            'per_page' => 100,
        ]);

        return array_values(array_filter($allIssues, fn(Issue $issue) =>
            in_array(strtolower($issue->state), $states, true)
        ));
    }

    public function fetchStatesByIds(array $ids): array
    {
        $states = [];

        foreach ($ids as $id) {
            try {
                $response = $this->http->get("{$this->baseUrl}/repos/{$this->owner}/{$this->repo}/issues/{$id}");
                $data = $response->json();
                $states[$id] = $this->determineState($data['labels'] ?? []);
            } catch (\Exception $e) {
                $this->logger->warning("Failed to fetch issue {$id}: {$e->getMessage()}");
            }
        }

        return $states;
    }

    public function ensureLabels(): array
    {
        $response = $this->http->get("{$this->baseUrl}/repos/{$this->owner}/{$this->repo}/labels", [
            'per_page' => 100,
        ]);
        $existing = array_map(fn($l) => strtolower($l['name']), $response->json());

        $needed = array_unique(array_merge($this->activeStates, $this->terminalStates));
        $created = [];

        foreach ($needed as $label) {
            if (!in_array($label, $existing, true)) {
                try {
                    $this->http->post("{$this->baseUrl}/repos/{$this->owner}/{$this->repo}/labels", [
                        'name' => $label,
                    ]);
                    $this->logger->info("Created label: {$label}");
                    $created[] = $label;
                } catch (\Exception $e) {
                    $this->logger->warning("Failed to create label '{$label}': {$e->getMessage()}");
                }
            }
        }

        return $created;
    }

    /**
     * @return Issue[]
     */
    private function fetchIssuesWithPagination(string $url, array $query): array
    {
        $issues = [];
        $params = $query;

        while ($url !== null) {
            $response = $this->http->get($url, $params);
            $data = $response->json();

            foreach ($data as $item) {
                // Skip pull requests (GitHub API returns them in issues endpoint)
                if (isset($item['pull_request'])) {
                    continue;
                }

                $issues[] = $this->normalizeIssue($item);
            }

            $url = $this->getNextPageUrl($response->header('Link'));
            $params = []; // Next URL already contains query params
        }

        return $issues;
    }

    private function normalizeIssue(array $data): Issue
    {
        $labels = array_map(fn($l) => is_array($l) ? $l['name'] : $l, $data['labels'] ?? []);
        $state = $this->determineState($labels);
        $priority = $this->extractPriority($labels);
        $blockedBy = $this->parseBlockedBy($data['body'] ?? '');
        $identifier = "{$this->repo}#{$data['number']}";
        $branchName = 'symphony/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $identifier);

        return new Issue(
            id: (string) $data['number'],
            identifier: $identifier,
            title: $data['title'],
            description: $data['body'] ?? '',
            priority: $priority,
            state: $state,
            branchName: $branchName,
            url: $data['html_url'] ?? '',
            labels: $labels,
            blockedBy: $blockedBy,
            createdAt: new DateTimeImmutable($data['created_at']),
            updatedAt: new DateTimeImmutable($data['updated_at']),
        );
    }

    private function determineState(array $labels): string
    {
        $labelNames = array_map(fn($l) => strtolower(is_array($l) ? $l['name'] : $l), $labels);

        foreach ($this->terminalStates as $state) {
            if (in_array($state, $labelNames, true)) {
                return $state;
            }
        }

        foreach ($this->activeStates as $state) {
            if (in_array($state, $labelNames, true)) {
                return $state;
            }
        }

        return 'unknown';
    }

    private function extractPriority(array $labels): ?int
    {
        foreach ($labels as $label) {
            $name = is_array($label) ? $label['name'] : $label;
            if (preg_match('/^priority[:\s]*(\d+)$/i', $name, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function parseBlockedBy(string $body): array
    {
        $blockedBy = [];

        if (preg_match_all('/blocked\s+by\s+#(\d+)/i', $body, $matches)) {
            $blockedBy = $matches[1];
        }

        return $blockedBy;
    }

    private function getNextPageUrl(?string $linkHeader): ?string
    {
        if ($linkHeader && preg_match('/<([^>]+)>;\s*rel="next"/', $linkHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
