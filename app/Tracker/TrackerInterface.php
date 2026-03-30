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
     * @param  string[]  $states
     * @return Issue[]
     */
    public function fetchIssuesByStates(array $states): array;

    /**
     * Fetch current states for a list of issue IDs (for reconciliation).
     *
     * @param  string[]  $ids
     * @return array<string, string> Map of id => state
     */
    public function fetchStatesByIds(array $ids): array;

    /**
     * Ensure configured state labels/statuses exist on the tracker.
     * Creates any that are missing. No-op for trackers that use
     * built-in workflow statuses (e.g., Jira).
     *
     * @param  string[]  $extraLabels  Additional labels to ensure (e.g., pipeline stage triggers)
     * @return string[] Labels that were created
     */
    public function ensureLabels(array $extraLabels = []): array;
}
