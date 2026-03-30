<?php

namespace App\Tracker;

use DateTimeImmutable;
use DateTimeZone;

class Issue
{
    public readonly string $id;
    public readonly string $identifier;
    public readonly string $title;
    public readonly string $description;
    public readonly ?int $priority;
    public readonly string $state;
    public readonly string $branchName;
    public readonly string $url;
    /** @var string[] */
    public readonly array $labels;
    /** @var string[] */
    public readonly array $blockedBy;
    public readonly DateTimeImmutable $createdAt;
    public readonly DateTimeImmutable $updatedAt;

    /**
     * @param string[] $labels
     * @param string[] $blockedBy
     */
    public function __construct(
        string $id,
        string $identifier,
        string $title,
        string $description,
        ?int $priority,
        string $state,
        string $branchName,
        string $url,
        array $labels,
        array $blockedBy,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ) {
        $this->id = $id;
        $this->identifier = $identifier;
        $this->title = $title;
        $this->description = $description;
        $this->priority = $priority;
        $this->state = $state;
        $this->branchName = $branchName;
        $this->url = $url;
        $this->labels = array_map('strtolower', $labels);
        $this->blockedBy = $blockedBy;
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
        $this->updatedAt = $updatedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'identifier' => $this->identifier,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'state' => $this->state,
            'branchName' => $this->branchName,
            'url' => $this->url,
            'labels' => $this->labels,
            'blockedBy' => $this->blockedBy,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
