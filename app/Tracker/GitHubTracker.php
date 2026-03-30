<?php

namespace App\Tracker;

use App\Config\WorkflowConfig;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

class GitHubTracker implements TrackerInterface
{
    private Client $client;
    private string $owner;
    private string $repo;
    private array $activeStates;
    private array $terminalStates;

    public function __construct(
        private WorkflowConfig $config,
        private LoggerInterface $logger,
        ?Client $client = null,
    ) {
        $repository = $config->trackerRepository();
        if (!$repository || !str_contains($repository, '/')) {
            throw new \InvalidArgumentException('GitHub tracker requires tracker.repository in owner/repo format');
        }

        [$this->owner, $this->repo] = explode('/', $repository, 2);
        $this->activeStates = array_map('strtolower', $config->trackerActiveStates());
        $this->terminalStates = array_map('strtolower', $config->trackerTerminalStates());

        $this->client = $client ?? new Client([
            'base_uri' => 'https://api.github.com',
            'headers' => [
                'Authorization' => "Bearer {$config->trackerApiKey()}",
                'Accept' => 'application/vnd.github.v3+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);
    }

    public function fetchCandidateIssues(): array
    {
        $labels = implode(',', $this->activeStates);

        return $this->fetchIssuesWithPagination("/repos/{$this->owner}/{$this->repo}/issues", [
            'state' => 'open',
            'labels' => $labels,
            'per_page' => 100,
        ]);
    }

    public function fetchIssuesByStates(array $states): array
    {
        $labels = implode(',', array_map('strtolower', $states));

        return $this->fetchIssuesWithPagination("/repos/{$this->owner}/{$this->repo}/issues", [
            'state' => 'open',
            'labels' => $labels,
            'per_page' => 100,
        ]);
    }

    public function fetchStatesByIds(array $ids): array
    {
        $states = [];

        foreach ($ids as $id) {
            try {
                $response = $this->client->get("/repos/{$this->owner}/{$this->repo}/issues/{$id}");
                $data = json_decode($response->getBody()->getContents(), true);
                $states[$id] = $this->determineState($data['labels'] ?? []);
            } catch (GuzzleException $e) {
                $this->logger->warning("Failed to fetch issue {$id}: {$e->getMessage()}");
            }
        }

        return $states;
    }

    /**
     * @return Issue[]
     */
    private function fetchIssuesWithPagination(string $uri, array $query): array
    {
        $issues = [];
        $url = $uri;
        $params = $query;

        while ($url !== null) {
            $response = $this->client->get($url, ['query' => $params]);
            $data = json_decode($response->getBody()->getContents(), true);

            foreach ($data as $item) {
                // Skip pull requests (GitHub API returns them in issues endpoint)
                if (isset($item['pull_request'])) {
                    continue;
                }

                $issues[] = $this->normalizeIssue($item);
            }

            $url = $this->getNextPageUrl($response->getHeader('Link'));
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

    private function getNextPageUrl(array $linkHeaders): ?string
    {
        foreach ($linkHeaders as $header) {
            if (preg_match('/<([^>]+)>;\s*rel="next"/', $header, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }
}
