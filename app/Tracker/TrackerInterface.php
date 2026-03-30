<?php

namespace App\Tracker;

interface TrackerInterface
{
    /**
     * Fetch issues in active states (candidates for dispatch).
     *
     * @return Issue[]
     */
    public function fetchCandidateIssues(): array;

    /**
     * Fetch issues filtered by the given states.
     *
     * @param string[] $states
     * @return Issue[]
     */
    public function fetchIssuesByStates(array $states): array;

    /**
     * Fetch current states for a list of issue IDs (for reconciliation).
     *
     * @param string[] $ids
     * @return array<string, string> Map of id => state
     */
    public function fetchStatesByIds(array $ids): array;
}
