<?php

use App\Prompt\PromptBuilder;

it('renders prompt with issue context', function () {
    $builder = new PromptBuilder();

    $result = $builder->render(
        'Fix {{ issue.identifier }}: {{ issue.title }}',
        ['identifier' => 'symphony#42', 'title' => 'Fix login bug'],
    );

    expect($result)->toBe('Fix symphony#42: Fix login bug');
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

    expect($result)->toBe('Created: 2025-01-15T15:00:00+00:00');
});

it('passes attempt as null for first attempt', function () {
    $builder = new PromptBuilder();

    $result = $builder->render(
        '{% if attempt %}Retry attempt {{ attempt }}{% else %}First try{% endif %}',
        ['identifier' => 'test'],
        null,
    );

    expect($result)->toBe('First try');
});

it('passes attempt as integer for retries', function () {
    $builder = new PromptBuilder();

    $result = $builder->render(
        '{% if attempt %}Retry attempt {{ attempt }}{% endif %}',
        ['identifier' => 'test'],
        3,
    );

    expect($result)->toBe('Retry attempt 3');
});
