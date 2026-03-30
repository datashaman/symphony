# Configuration Reference

Workflow files use YAML frontmatter to configure Symphony. All keys below are set in the YAML section between `---` delimiters.

String values support environment variable substitution: `$VAR` or `${VAR}`. An error is thrown at startup if a referenced variable is not set.

## tracker

| Key | Type | Required | Default | Description |
|-----|------|----------|---------|-------------|
| `tracker.kind` | string | Yes | — | Tracker type: `github` or `jira` |
| `tracker.api_key` | string | Yes | — | API authentication token |
| `tracker.repository` | string | GitHub only | — | GitHub `owner/repo` |
| `tracker.endpoint` | string | Jira only | — | Jira Cloud base URL |
| `tracker.project_slug` | string | Jira only | — | Jira project key (e.g., `PROJ`) |
| `tracker.email` | string | Jira only | — | Email for Jira API auth |
| `tracker.assignee` | string | Jira only | `currentUser()` | Jira assignee filter. Set to `none` to disable. |
| `tracker.sprint` | string | Jira only | `openSprints()` | Jira sprint filter. Set to `none` to disable. |
| `tracker.jql` | string | Jira only | — | Custom JQL override. Replaces the auto-generated query. |
| `tracker.active_states` | string[] | No | `['Todo', 'In Progress']` | Issue states to treat as workable |
| `tracker.terminal_states` | string[] | No | `['Closed', 'Cancelled', 'Canceled', 'Duplicate', 'Done']` | Issue states to treat as finished |

## polling

| Key | Type | Required | Default | Description |
|-----|------|----------|---------|-------------|
| `polling.interval_ms` | int | No | `30000` | Milliseconds between orchestration ticks |

## workspace

| Key | Type | Required | Default | Description |
|-----|------|----------|---------|-------------|
| `workspace.root` | string | No | `<repo_root>/.symphony/worktrees` | Base directory for issue worktrees |
| `workspace.setup` | string[] | No | `[]` | Shell commands to run after creating a new worktree. Supports `%BASE%` placeholder for repo root. |
| `workspace.setup_timeout_ms` | int | No | `60000` | Maximum time (ms) for each setup command |

## agent

| Key | Type | Required | Default | Description |
|-----|------|----------|---------|-------------|
| `agent.max_concurrent_agents` | int | No | `10` | Maximum simultaneous agent processes |
| `agent.max_turns` | int | No | `20` | Maximum turns per agent session |
| `agent.max_retry_backoff_ms` | int | No | `300000` | Maximum retry delay cap (ms) |

## claude

The entire `claude` section is optional. Sensible defaults are provided.

| Key | Type | Required | Default | Description |
|-----|------|----------|---------|-------------|
| `claude.command` | string | No | `claude -p --verbose --output-format stream-json --dangerously-skip-permissions` | Claude Code CLI command |
| `claude.turn_timeout_ms` | int | No | `3600000` | Maximum wall-clock time per turn (ms) |
| `claude.stall_timeout_ms` | int | No | `300000` | Maximum time without output before killing (ms) |

## Complete Example

```yaml
---
tracker:
  kind: github
  repository: datashaman/my-project
  api_key: $GITHUB_TOKEN
  active_states:
    - todo
    - in-progress
  terminal_states:
    - done
    - closed
    - cancelled

polling:
  interval_ms: 30000

workspace:
  setup:
    - "cp %BASE%/.env .env"
    - "composer install --no-interaction --no-progress"
  setup_timeout_ms: 120000

agent:
  max_concurrent_agents: 5
  max_turns: 20
  max_retry_backoff_ms: 300000

claude:
  turn_timeout_ms: 3600000
  stall_timeout_ms: 300000
---
```
