<?php

namespace App\Tracker;

use App\Config\WorkflowConfig;
use DateTimeImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

class JiraTracker implements TrackerInterface
{
    private PendingRequest $http;
    private string $baseUrl;
    private string $projectSlug;
    private array $activeStates;

    public function __construct(
        private WorkflowConfig $config,
        private LoggerInterface $logger,
        ?PendingRequest $http = null,
    ) {
        $endpoint = $config->trackerEndpoint();
        if (!$endpoint) {
            throw new InvalidArgumentException('Jira tracker requires tracker.endpoint');
        }

        $this->baseUrl = rtrim($endpoint, '/');
        $this->projectSlug = $config->trackerProjectSlug()
            ?? throw new InvalidArgumentException('Jira tracker requires tracker.project_slug');

        $email = $config->trackerEmail()
            ?? throw new InvalidArgumentException('Jira tracker requires tracker.email');

        $this->activeStates = $config->trackerActiveStates();

        $this->http = $http ?? Http::withBasicAuth($email, $config->trackerApiKey())
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->throw();
    }

    public function fetchCandidateIssues(): array
    {
        return $this->fetchWithJql($this->buildJql($this->activeStates));
    }

    public function fetchIssuesByStates(array $states): array
    {
        return $this->fetchWithJql($this->buildJql($states));
    }

    public function fetchStatesByIds(array $ids): array
    {
        $states = [];

        foreach ($ids as $id) {
            try {
                $response = $this->http->get("{$this->baseUrl}/rest/api/3/issue/{$id}", [
                    'fields' => 'status',
                ]);
                $data = $response->json();
                $states[$id] = $data['fields']['status']['name'] ?? 'unknown';
            } catch (\Exception $e) {
                $this->logger->warning("Failed to fetch Jira issue {$id}: {$e->getMessage()}");
            }
        }

        return $states;
    }

    public function ensureLabels(): void
    {
        // Jira uses workflow statuses, not labels — no-op
    }

    private function buildJql(array $states): string
    {
        // Allow full override via tracker.jql
        $customJql = $this->config->trackerJql();
        if ($customJql) {
            return $customJql;
        }

        $quotedStates = array_map(fn($s) => '"' . addslashes($s) . '"', $states);
        $stateList = implode(',', $quotedStates);

        $clauses = [
            "project = {$this->projectSlug}",
            "status in ({$stateList})",
        ];

        $assignee = $this->config->trackerAssignee();
        if ($assignee !== '' && $assignee !== 'none') {
            $clauses[] = "assignee = {$assignee}";
        }

        $sprint = $this->config->trackerSprint();
        if ($sprint !== '' && $sprint !== 'none') {
            $clauses[] = "sprint in {$sprint}";
        }

        return implode(' AND ', $clauses);
    }

    /**
     * @return Issue[]
     */
    private function fetchWithJql(string $jql): array
    {
        $issues = [];
        $startAt = 0;
        $maxResults = 50;

        do {
            $response = $this->http->get("{$this->baseUrl}/rest/api/3/search", [
                'jql' => $jql,
                'startAt' => $startAt,
                'maxResults' => $maxResults,
                'fields' => 'summary,description,status,priority,labels,issuelinks,created,updated',
            ]);

            $data = $response->json();
            $total = $data['total'] ?? 0;

            foreach ($data['issues'] ?? [] as $item) {
                $issues[] = $this->normalizeIssue($item);
            }

            $startAt += $maxResults;
        } while ($startAt < $total);

        return $issues;
    }

    private function normalizeIssue(array $data): Issue
    {
        $fields = $data['fields'] ?? [];
        $key = $data['key'];
        $blockedBy = $this->extractBlockedBy($fields['issuelinks'] ?? []);
        $branchName = 'symphony/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $key);

        return new Issue(
            id: (string) $data['id'],
            identifier: $key,
            title: $fields['summary'] ?? '',
            description: $this->extractDescription($fields['description'] ?? null),
            priority: isset($fields['priority']['id']) ? (int) $fields['priority']['id'] : null,
            state: $fields['status']['name'] ?? 'unknown',
            branchName: $branchName,
            url: $data['self'] ?? '',
            labels: $fields['labels'] ?? [],
            blockedBy: $blockedBy,
            createdAt: new DateTimeImmutable($fields['created'] ?? 'now'),
            updatedAt: new DateTimeImmutable($fields['updated'] ?? 'now'),
        );
    }

    private function extractBlockedBy(array $issueLinks): array
    {
        $blockedBy = [];

        foreach ($issueLinks as $link) {
            $typeName = strtolower($link['type']['name'] ?? '');
            if ($typeName === 'blocks' && isset($link['inwardIssue']['key'])) {
                $blockedBy[] = $link['inwardIssue']['key'];
            }
        }

        return $blockedBy;
    }

    private function extractDescription(?array $adf): string
    {
        if ($adf === null) {
            return '';
        }

        // Simple ADF to text extraction
        $text = '';
        $this->walkAdf($adf, $text);

        return trim($text);
    }

    private function walkAdf(array $node, string &$text): void
    {
        if (isset($node['text'])) {
            $text .= $node['text'];
        }

        if (isset($node['content']) && is_array($node['content'])) {
            foreach ($node['content'] as $child) {
                $this->walkAdf($child, $text);
            }
            if (in_array($node['type'] ?? '', ['paragraph', 'heading', 'bulletList', 'orderedList'])) {
                $text .= "\n";
            }
        }
    }
}
