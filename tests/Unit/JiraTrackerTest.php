<?php

use App\Config\WorkflowConfig;
use App\Tracker\JiraTracker;
use Illuminate\Support\Facades\Http;
use Psr\Log\NullLogger;

function makeJiraConfig(): WorkflowConfig
{
    putenv('JIRA_TOKEN=jira_test_token');
    $config = new WorkflowConfig([
        'tracker' => [
            'kind' => 'jira',
            'api_key' => '$JIRA_TOKEN',
            'endpoint' => 'https://jira.example.com',
            'project_slug' => 'PROJ',
            'email' => 'user@example.com',
            'active_states' => ['To Do', 'In Progress'],
            'terminal_states' => ['Done', 'Closed'],
        ],
    ]);
    putenv('JIRA_TOKEN');

    return $config;
}

it('fetches candidate issues via JQL', function () {
    Http::fake([
        'jira.example.com/rest/api/3/search*' => Http::response([
            'total' => 1,
            'issues' => [
                [
                    'id' => '10001',
                    'key' => 'PROJ-123',
                    'self' => 'https://jira.example.com/rest/api/3/issue/10001',
                    'fields' => [
                        'summary' => 'Fix the thing',
                        'description' => null,
                        'status' => ['name' => 'In Progress'],
                        'priority' => ['id' => '2'],
                        'labels' => ['backend'],
                        'issuelinks' => [],
                        'created' => '2025-01-15T10:00:00.000+0000',
                        'updated' => '2025-01-16T10:00:00.000+0000',
                    ],
                ],
            ],
        ]),
    ]);

    $tracker = new JiraTracker(makeJiraConfig(), new NullLogger());
    $issues = $tracker->fetchCandidateIssues();

    expect($issues)->toHaveCount(1);
    expect($issues[0]->identifier)->toBe('PROJ-123');
    expect($issues[0]->state)->toBe('In Progress');
    expect($issues[0]->priority)->toBe(2);
    expect($issues[0]->branchName)->toBe('symphony/PROJ-123');
});

it('handles pagination with startAt/maxResults', function () {
    Http::fake([
        'jira.example.com/rest/api/3/search*' => Http::sequence()
            ->push([
                'total' => 60,
                'issues' => array_map(fn($i) => [
                    'id' => (string) $i,
                    'key' => "PROJ-{$i}",
                    'self' => '',
                    'fields' => [
                        'summary' => "Issue {$i}",
                        'description' => null,
                        'status' => ['name' => 'To Do'],
                        'priority' => null,
                        'labels' => [],
                        'issuelinks' => [],
                        'created' => '2025-01-01T00:00:00.000+0000',
                        'updated' => '2025-01-01T00:00:00.000+0000',
                    ],
                ], range(1, 50)),
            ])
            ->push([
                'total' => 60,
                'issues' => array_map(fn($i) => [
                    'id' => (string) $i,
                    'key' => "PROJ-{$i}",
                    'self' => '',
                    'fields' => [
                        'summary' => "Issue {$i}",
                        'description' => null,
                        'status' => ['name' => 'To Do'],
                        'priority' => null,
                        'labels' => [],
                        'issuelinks' => [],
                        'created' => '2025-01-01T00:00:00.000+0000',
                        'updated' => '2025-01-01T00:00:00.000+0000',
                    ],
                ], range(51, 60)),
            ]),
    ]);

    $tracker = new JiraTracker(makeJiraConfig(), new NullLogger());
    $issues = $tracker->fetchCandidateIssues();

    expect($issues)->toHaveCount(60);
});

it('extracts blocked-by from issue links', function () {
    Http::fake([
        'jira.example.com/rest/api/3/search*' => Http::response([
            'total' => 1,
            'issues' => [
                [
                    'id' => '10002',
                    'key' => 'PROJ-200',
                    'self' => '',
                    'fields' => [
                        'summary' => 'Blocked issue',
                        'description' => null,
                        'status' => ['name' => 'To Do'],
                        'priority' => null,
                        'labels' => [],
                        'issuelinks' => [
                            [
                                'type' => ['name' => 'Blocks'],
                                'inwardIssue' => ['key' => 'PROJ-99'],
                            ],
                        ],
                        'created' => '2025-01-01T00:00:00.000+0000',
                        'updated' => '2025-01-01T00:00:00.000+0000',
                    ],
                ],
            ],
        ]),
    ]);

    $tracker = new JiraTracker(makeJiraConfig(), new NullLogger());
    $issues = $tracker->fetchCandidateIssues();

    expect($issues[0]->blockedBy)->toBe(['PROJ-99']);
});

it('fetches states by IDs for reconciliation', function () {
    Http::fake([
        'jira.example.com/rest/api/3/issue/10001*' => Http::response([
            'fields' => ['status' => ['name' => 'In Progress']],
        ]),
        'jira.example.com/rest/api/3/issue/10002*' => Http::response([
            'fields' => ['status' => ['name' => 'Done']],
        ]),
    ]);

    $tracker = new JiraTracker(makeJiraConfig(), new NullLogger());
    $states = $tracker->fetchStatesByIds(['10001', '10002']);

    expect($states)->toBe(['10001' => 'In Progress', '10002' => 'Done']);
});
