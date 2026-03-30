<?php

use App\Tracker\Issue;

it('normalizes labels to lowercase', function () {
    $issue = new Issue(
        id: '42',
        identifier: 'symphony#42',
        title: 'Test issue',
        description: 'Description',
        priority: 1,
        state: 'Todo',
        branchName: 'symphony/symphony_42',
        url: 'https://github.com/test/repo/issues/42',
        labels: ['Todo', 'Priority:1', 'URGENT'],
        blockedBy: [],
        createdAt: new DateTimeImmutable('2025-01-15T10:00:00+00:00'),
        updatedAt: new DateTimeImmutable('2025-01-15T12:00:00+00:00'),
    );

    expect($issue->labels)->toBe(['todo', 'priority:1', 'urgent']);
});

it('normalizes timestamps to UTC', function () {
    $issue = new Issue(
        id: '42',
        identifier: 'symphony#42',
        title: 'Test issue',
        description: 'Description',
        priority: null,
        state: 'Todo',
        branchName: 'symphony/symphony_42',
        url: 'https://github.com/test/repo/issues/42',
        labels: [],
        blockedBy: [],
        createdAt: new DateTimeImmutable('2025-01-15T10:00:00-05:00'),
        updatedAt: new DateTimeImmutable('2025-01-15T10:00:00-05:00'),
    );

    expect($issue->createdAt->getTimezone()->getName())->toBe('UTC');
    expect($issue->createdAt->format('Y-m-d\TH:i:sP'))->toBe('2025-01-15T15:00:00+00:00');
});

it('preserves all fields correctly', function () {
    $issue = new Issue(
        id: '99',
        identifier: 'PROJ-123',
        title: 'Fix the thing',
        description: 'It is broken',
        priority: 2,
        state: 'In Progress',
        branchName: 'symphony/PROJ-123',
        url: 'https://jira.example.com/browse/PROJ-123',
        labels: ['backend'],
        blockedBy: ['PROJ-99'],
        createdAt: new DateTimeImmutable('2025-03-01T00:00:00+00:00'),
        updatedAt: new DateTimeImmutable('2025-03-02T00:00:00+00:00'),
    );

    expect($issue->id)->toBe('99');
    expect($issue->identifier)->toBe('PROJ-123');
    expect($issue->priority)->toBe(2);
    expect($issue->state)->toBe('In Progress');
    expect($issue->blockedBy)->toBe(['PROJ-99']);
});

it('supports null priority', function () {
    $issue = new Issue(
        id: '1',
        identifier: 'test#1',
        title: 'No priority',
        description: '',
        priority: null,
        state: 'Todo',
        branchName: 'symphony/test_1',
        url: '',
        labels: [],
        blockedBy: [],
        createdAt: new DateTimeImmutable('now'),
        updatedAt: new DateTimeImmutable('now'),
    );

    expect($issue->priority)->toBeNull();
});
