# Configure the Jira Tracker

## Basic Setup

Set `tracker.kind` to `jira` in your workflow file:

```yaml
tracker:
  kind: jira
  endpoint: $JIRA_BASE_URL
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
| `tracker.active_states` | No | States to treat as workable (default: `['Todo', 'In Progress']`) |
| `tracker.terminal_states` | No | States to treat as finished (default: `['Closed', 'Cancelled', 'Canceled', 'Duplicate', 'Done']`) |
| `tracker.assignee` | No | Filter by assignee. Default: `currentUser()`. Set to `none` to disable. |
| `tracker.sprint` | No | Filter by sprint. Default: `openSprints()`. Set to `none` to disable. |
| `tracker.jql` | No | Custom JQL override. When set, replaces the auto-generated query entirely. |

## How Candidate Issues Are Fetched

The Jira tracker builds a JQL query and paginates through results (50 per page).

**Default JQL construction:**
```
project = PROJ AND status in ("To Do","In Progress") AND assignee = currentUser() AND sprint in openSprints()
```

Set `tracker.jql` to override with a fully custom query:
```yaml
tracker:
  jql: "project = PROJ AND labels = symphony AND status != Done"
```

Set `tracker.assignee: none` or `tracker.sprint: none` to omit those clauses from the default query.

**Fields fetched:** summary, description, status, priority, labels, issuelinks, created, updated.

## State Mapping

Jira states are matched by the issue's workflow `status.name` field. Use the exact status names from your Jira workflow:

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

Jira's native `priority.id` field is used directly as a numeric value for sorting.

## Blocked Issues

The Jira tracker reads the `issuelinks` field. Links where `type.name` is "blocks" and an `inwardIssue.key` exists are recorded as blockers. Symphony skips blocked issues until all blockers resolve.

## Description Handling

Jira stores descriptions in Atlassian Document Format (ADF). The tracker recursively walks ADF nodes to extract plain text, adding newlines after block elements (paragraphs, headings, lists).

## Label Management

Unlike the GitHub tracker, the Jira tracker does not manage labels — Jira uses workflow statuses for state tracking, so `ensureLabels()` is a no-op.
