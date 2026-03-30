## ADDED Requirements

### Requirement: Normalized Issue model
The system SHALL represent issues as a DTO with fields: `id` (string), `identifier` (string), `title` (string), `description` (string), `priority` (int|null), `state` (string), `branchName` (string), `url` (string), `labels` (string[]), `blockedBy` (string[]), `createdAt` (DateTimeImmutable), `updatedAt` (DateTimeImmutable). Labels SHALL be normalized to lowercase. Timestamps SHALL be normalized to UTC.

#### Scenario: GitHub issue normalized
- **WHEN** a GitHub issue has labels `["Todo", "Priority:1"]`, created at `"2025-01-15T10:00:00-05:00"`
- **THEN** the Issue DTO has `labels: ["todo", "priority:1"]` and `createdAt` in UTC (`"2025-01-15T15:00:00+00:00"`)

#### Scenario: Jira issue normalized
- **WHEN** a Jira issue has status name `"In Progress"`, priority id `2`, and key `"PROJ-123"`
- **THEN** the Issue DTO has `state: "In Progress"`, `priority: 2`, `identifier: "PROJ-123"`

### Requirement: TrackerInterface contract
The system SHALL define a `TrackerInterface` with three methods:
- `fetchCandidateIssues(): array` -- returns issues in active states
- `fetchIssuesByStates(array $states): array` -- returns issues filtered by given states
- `fetchStatesByIds(array $ids): array` -- returns `[id => state]` map for reconciliation

#### Scenario: Interface enforces all methods
- **WHEN** a class implements TrackerInterface
- **THEN** it MUST implement all three methods or PHP raises a fatal error

### Requirement: GitHub tracker implementation
The system SHALL implement `TrackerInterface` for GitHub Issues using the REST API. State mapping SHALL use GitHub labels matched against `tracker.active_states` and `tracker.terminal_states`. Pagination SHALL follow `Link` header. The `identifier` SHALL be `"{repo}#{number}"`. The `branchName` SHALL be `"symphony/{identifier}"` with non-alphanumeric characters (except `.`, `_`, `-`) replaced by `_`.

#### Scenario: Fetch candidate issues
- **WHEN** `fetchCandidateIssues()` is called with active_states `["todo", "in-progress"]`
- **THEN** the system queries `GET /repos/{owner}/{repo}/issues?state=open&labels=todo,in-progress&per_page=100` and returns normalized Issue DTOs

#### Scenario: Pagination with Link header
- **WHEN** the GitHub API response includes a `Link` header with `rel="next"`
- **THEN** the system follows all next links until no more pages remain

#### Scenario: Fetch states by IDs for reconciliation
- **WHEN** `fetchStatesByIds(["42", "99"])` is called
- **THEN** the system fetches each issue individually and returns `["42" => "todo", "99" => "done"]` based on current labels

#### Scenario: Blocked-by detection
- **WHEN** a GitHub issue body contains `"blocked by #123"`
- **THEN** the Issue DTO `blockedBy` includes `"123"`

### Requirement: Jira tracker implementation
The system SHALL implement `TrackerInterface` for Jira using the REST API v3. Authentication SHALL use Basic auth (email:api_token base64). Candidate fetch SHALL use JQL: `project={key} AND status in ({active_states})`. Pagination SHALL use `startAt`/`maxResults`. Blocked-by SHALL use issue links of type "Blocks" (inward).

#### Scenario: Fetch candidate issues via JQL
- **WHEN** `fetchCandidateIssues()` is called with project `"PROJ"` and active_states `["To Do", "In Progress"]`
- **THEN** the system queries with JQL `project=PROJ AND status in ("To Do","In Progress")`

#### Scenario: Jira pagination
- **WHEN** the Jira search returns `total: 150` with `maxResults: 50`
- **THEN** the system makes 3 requests with `startAt` values of 0, 50, and 100

#### Scenario: Jira blocked-by from issue links
- **WHEN** a Jira issue has an issue link of type "Blocks" with inward issue key `"PROJ-99"`
- **THEN** the Issue DTO `blockedBy` includes `"PROJ-99"`
