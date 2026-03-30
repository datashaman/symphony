# Configuration Reference

Workflow files use YAML frontmatter to configure Symphony. All keys below are set in the YAML section between `---` delimiters.

String values support environment variable substitution: `$VAR` or `${VAR}`.

## tracker

| Key | Type | Required | Default | Description |
|-----|------|----------|---------|-------------|
| `tracker.kind` | string | Yes | - | Tracker type: `github` or `jira` |
| `tracker.api_key` | string | Yes | - | API authentication token |
| `tracker.repository` | string | GitHub only | - | GitHub `owner/repo` |
| `tracker.endpoint` | string | Jira only | - | Jira Cloud base URL |
| `tracker.project_slug` | string | Jira only | - | Jira project key (e.g., `PROJ`) |
| `tracker.email` | string | Jira only | - | Email for Jira API auth |
| `tracker.active_states` | string[] | No | `['Todo', 'In Progress']` | Issue states to treat as workable |
| `tracker.terminal_states` | string[] | No | `['Closed', 'Cancelled', 'Canceled', 'Duplicate', 'Done']` | Issue states to treat as finished |

## polling

| Key | Type | Required | Default | Description |
|-----|------|----------|---------|-------------|
| `polling.interval_ms` | int | No | `30000` | Milliseconds between orchestration ticks |

## workspace

| Key | Type | Required | Default | Description |
|-----|------|----------|---------|-------------|
| `workspace.root` | string | No | `<sys_temp_dir>/symphony_workspaces` | Base directory for issue workspaces |
| `workspace.hooks.after_create` | string[] | No | `[]` | Commands to run after creating a workspace |
| `workspace.hooks.before_run` | string[] | No | `[]` | Commands to run before launching the agent |
| `workspace.hooks.before_remove` | string[] | No | `[]` | Commands to run before deleting a workspace |

## hooks

| Key | Type | Required | Default | Description |
|-----|------|----------|---------|-------------|
| `hooks.timeout_ms` | int | No | `60000` | Maximum time (ms) for each hook command |

## agent

| Key | Type | Required | Default | Description |
|-----|------|----------|---------|-------------|
| `agent.max_concurrent_agents` | int | No | `10` | Maximum simultaneous agent processes |
| `agent.max_turns` | int | No | `20` | Maximum turns per agent session |
| `agent.max_retry_backoff_ms` | int | No | `300000` | Maximum retry delay cap (ms) |

## codex

| Key | Type | Required | Default | Description |
|-----|------|----------|---------|-------------|
| `codex.command` | string | No | `claude -p --output-format stream-json` | Claude Code CLI command |
| `codex.turn_timeout_ms` | int | No | `3600000` | Maximum wall-clock time per turn (ms) |
| `codex.stall_timeout_ms` | int | No | `300000` | Maximum time without output before killing (ms) |

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
  root: /tmp/symphony_workspaces
  hooks:
    after_create:
      - "git clone https://github.com/datashaman/my-project.git ."
    before_run:
      - "git pull origin main"

hooks:
  timeout_ms: 120000

agent:
  max_concurrent_agents: 5
  max_turns: 20
  max_retry_backoff_ms: 300000

codex:
  command: "claude -p --output-format stream-json"
  turn_timeout_ms: 3600000
  stall_timeout_ms: 300000
---
```
