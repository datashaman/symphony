<?php

use App\Config\WorkflowConfig;
use App\Tracker\GitHubTracker;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;

function makeGitHubConfig(): WorkflowConfig
{
    putenv('GH_TOKEN=ghp_test');
    $config = new WorkflowConfig([
        'tracker' => [
            'kind' => 'github',
            'api_key' => '$GH_TOKEN',
            'repository' => 'datashaman/my-project',
            'active_states' => ['todo', 'in-progress'],
            'terminal_states' => ['done', 'closed'],
        ],
    ]);
    putenv('GH_TOKEN');

    return $config;
}

function makeGitHubTracker(MockHandler $mock): GitHubTracker
{
    $handlerStack = HandlerStack::create($mock);
    $client = new Client(['handler' => $handlerStack]);

    return new GitHubTracker(makeGitHubConfig(), new NullLogger(), $client);
}

it('fetches candidate issues', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            [
                'number' => 42,
                'title' => 'Fix login bug',
                'body' => 'The login is broken',
                'labels' => [['name' => 'todo']],
                'html_url' => 'https://github.com/datashaman/my-project/issues/42',
                'created_at' => '2025-01-15T10:00:00Z',
                'updated_at' => '2025-01-16T10:00:00Z',
            ],
        ])),
    ]);

    $tracker = makeGitHubTracker($mock);
    $issues = $tracker->fetchCandidateIssues();

    expect($issues)->toHaveCount(1);
    expect($issues[0]->id)->toBe('42');
    expect($issues[0]->identifier)->toBe('my-project#42');
    expect($issues[0]->title)->toBe('Fix login bug');
    expect($issues[0]->state)->toBe('todo');
    expect($issues[0]->branchName)->toBe('symphony/my-project_42');
});

it('handles pagination via Link header', function () {
    $mock = new MockHandler([
        new Response(200, [
            'Link' => '<https://api.github.com/repos/datashaman/my-project/issues?page=2>; rel="next"',
        ], json_encode([
            [
                'number' => 1,
                'title' => 'Issue 1',
                'body' => '',
                'labels' => [['name' => 'todo']],
                'html_url' => '',
                'created_at' => '2025-01-01T00:00:00Z',
                'updated_at' => '2025-01-01T00:00:00Z',
            ],
        ])),
        new Response(200, [], json_encode([
            [
                'number' => 2,
                'title' => 'Issue 2',
                'body' => '',
                'labels' => [['name' => 'in-progress']],
                'html_url' => '',
                'created_at' => '2025-01-02T00:00:00Z',
                'updated_at' => '2025-01-02T00:00:00Z',
            ],
        ])),
    ]);

    $tracker = makeGitHubTracker($mock);
    $issues = $tracker->fetchCandidateIssues();

    expect($issues)->toHaveCount(2);
    expect($issues[0]->id)->toBe('1');
    expect($issues[1]->id)->toBe('2');
});

it('fetches states by IDs for reconciliation', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'number' => 42,
            'labels' => [['name' => 'todo']],
        ])),
        new Response(200, [], json_encode([
            'number' => 99,
            'labels' => [['name' => 'done']],
        ])),
    ]);

    $tracker = makeGitHubTracker($mock);
    $states = $tracker->fetchStatesByIds(['42', '99']);

    expect($states)->toBe(['42' => 'todo', '99' => 'done']);
});

it('detects blocked-by from issue body', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            [
                'number' => 50,
                'title' => 'Blocked issue',
                'body' => 'This is blocked by #123 and blocked by #456',
                'labels' => [['name' => 'todo']],
                'html_url' => '',
                'created_at' => '2025-01-01T00:00:00Z',
                'updated_at' => '2025-01-01T00:00:00Z',
            ],
        ])),
    ]);

    $tracker = makeGitHubTracker($mock);
    $issues = $tracker->fetchCandidateIssues();

    expect($issues[0]->blockedBy)->toBe(['123', '456']);
});

it('extracts priority from labels', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            [
                'number' => 10,
                'title' => 'Priority issue',
                'body' => '',
                'labels' => [['name' => 'todo'], ['name' => 'priority:1']],
                'html_url' => '',
                'created_at' => '2025-01-01T00:00:00Z',
                'updated_at' => '2025-01-01T00:00:00Z',
            ],
        ])),
    ]);

    $tracker = makeGitHubTracker($mock);
    $issues = $tracker->fetchCandidateIssues();

    expect($issues[0]->priority)->toBe(1);
});

it('skips pull requests in issues response', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            [
                'number' => 10,
                'title' => 'A PR',
                'body' => '',
                'labels' => [['name' => 'todo']],
                'html_url' => '',
                'created_at' => '2025-01-01T00:00:00Z',
                'updated_at' => '2025-01-01T00:00:00Z',
                'pull_request' => ['url' => 'https://...'],
            ],
            [
                'number' => 11,
                'title' => 'An issue',
                'body' => '',
                'labels' => [['name' => 'todo']],
                'html_url' => '',
                'created_at' => '2025-01-01T00:00:00Z',
                'updated_at' => '2025-01-01T00:00:00Z',
            ],
        ])),
    ]);

    $tracker = makeGitHubTracker($mock);
    $issues = $tracker->fetchCandidateIssues();

    expect($issues)->toHaveCount(1);
    expect($issues[0]->id)->toBe('11');
});
