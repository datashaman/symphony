# Configure the Jira Tracker

## Basic Setup

Set `tracker.kind` to `jira` in your workflow file:

```yaml
tracker:
  kind: jira
  endpoint: https://your-domain.atlassian.net
  project_slug: PROJ
  email: $JIRA_EMAIL
  api_key: $JIRA_API_TOKEN
  active_states:
    - To Do
    - In Progress
  terminal_states:
    - Done
    - Closed
    - Cancelled
```

## Authentication

Jira Cloud uses email + API token authentication. Generate an API token at [Atlassian API Tokens](https://id.atlassian.com/manage-profile/security/api-tokens).

Store credentials in your `.env`:

```
JIRA_BASE_URL=https://your-domain.atlassian.net
JIRA_EMAIL=you@example.com
JIRA_API_TOKEN=xxxxxxxxxxxxxxxxxxxxxxxx
```

## Configuration Keys

| Key | Required | Description |
|-----|----------|-------------|
| `tracker.endpoint` | Yes | Jira Cloud base URL (no trailing slash) |
| `tracker.project_slug` | Yes | Jira project key (e.g., `PROJ`) |
| `tracker.email` | Yes | Email for API authentication |
| `tracker.api_key` | Yes | Jira API token |
| `tracker.active_states` | No | States to treat as workable (default: `['To Do', 'In Progress']`) |
| `tracker.terminal_states` | No | States to treat as finished (default: `['Done', 'Closed', 'Cancelled', 'Canceled', 'Duplicate']`) |

## How Candidate Issues Are Fetched

The Jira tracker:
1. Builds a JQL query: `project = <project_slug> AND status IN (<active_states>)`
2. Queries the Jira REST API v3 (`/rest/api/3/search`)
3. Paginates through all results
4. Maps Jira fields to the normalized Issue DTO

## State Mapping

Jira states are matched by the issue's workflow status name (case-insensitive). Use the exact status names from your Jira workflow:

```yaml
tracker:
  active_states:
    - To Do
    - In Progress
    - In Review
  terminal_states:
    - Done
    - Won't Do
```

## Priority

Jira's native priority field is used directly. The tracker maps Jira priority names to numeric values for sorting (Highest=1, High=2, Medium=3, Low=4, Lowest=5).

## Blocked Issues

The Jira tracker reads the `issuelinks` field to detect blocking relationships. If an issue is blocked by another issue that is in an active state, Symphony skips it until the blocker resolves.
