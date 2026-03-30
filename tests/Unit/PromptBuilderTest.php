<?php

use App\Prompt\PromptBuilder;

it('renders prompt with issue context', function () {
    $builder = new PromptBuilder();

    $result = $builder->render(
        'Fix {{ issue.identifier }}: {{ issue.title }}',
        ['identifier' => 'symphony#42', 'title' => 'Fix login bug'],
    );

    expect($result)->toStartWith('Fix symphony#42: Fix login bug');
});

it('throws on undefined variable', function () {
    $builder = new PromptBuilder();

    $builder->render(
        '{{ issue.nonexistent_field }}',
        ['identifier' => 'test'],
    );
})->throws(\Twig\Error\RuntimeError::class);

it('converts DateTime fields to ISO-8601 strings', function () {
    $builder = new PromptBuilder();

    $result = $builder->render(
        'Created: {{ issue.createdAt }}',
        ['createdAt' => new DateTimeImmutable('2025-01-15T15:00:00+00:00')],
    );

    expect($result)->toStartWith('Created: 2025-01-15T15:00:00+00:00');
});

it('passes attempt as null for first attempt', function () {
    $builder = new PromptBuilder();

    $result = $builder->render(
        '{% if attempt %}Retry attempt {{ attempt }}{% else %}First try{% endif %}',
        ['identifier' => 'test'],
        null,
    );

    expect($result)->toStartWith('First try');
});

it('passes attempt as integer for retries', function () {
    $builder = new PromptBuilder();

    $result = $builder->render(
        '{% if attempt %}Retry attempt {{ attempt }}{% endif %}',
        ['identifier' => 'test'],
        3,
    );

    expect($result)->toStartWith('Retry attempt 3');
});

it('appends prime directive with issue URL for closing reference', function () {
    $builder = new PromptBuilder();

    $result = $builder->render(
        'Fix the bug',
        [
            'identifier' => 'symphony#42',
            'title' => 'Fix login bug',
            'url' => 'https://github.com/datashaman/symphony/issues/42',
        ],
    );

    expect($result)->toContain('## Prime Directive');
    expect($result)->toContain('Closes https://github.com/datashaman/symphony/issues/42');
    expect($result)->toContain('referencing symphony#42');
    expect($result)->toContain('Create a pull request');
});

it('appends prime directive with identifier when URL is empty', function () {
    $builder = new PromptBuilder();

    $result = $builder->render(
        'Fix the bug',
        ['identifier' => 'PROJ-123', 'title' => 'Fix login bug'],
    );

    expect($result)->toContain('Closes PROJ-123');
});
