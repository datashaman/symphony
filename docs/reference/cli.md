# CLI Reference

## Usage

```
./application run [workflow]
```

### Arguments

| Argument | Required | Default | Description |
|----------|----------|---------|-------------|
| `workflow` | No | `./WORKFLOW.md` | Path to the workflow file |

### Examples

```bash
# Run with default workflow file
./application run

# Run with a specific workflow
./application run my-project.md

# Run with a Jira workflow
./application run WORKFLOW.jira.md
```

## Exit Codes

| Code | Meaning |
|------|---------|
| `0` | Clean shutdown (via SIGINT/SIGTERM) |
| `1` | Startup failure (workflow file not found, invalid config, tracker creation failed) |

Child processes (forked per issue) use these exit codes:

| Code | Meaning |
|------|---------|
| `0` | Agent completed successfully |
| `1` | Agent completed but reported failure |
| `2` | Unhandled exception in child process |

## Signal Handling

| Signal | Behavior |
|--------|----------|
| `SIGINT` (Ctrl+C) | Initiates graceful shutdown. Stops accepting new issues, waits for running agents to finish. |
| `SIGTERM` | Same as SIGINT. |

The shutdown sequence:
1. Sets a shutdown flag
2. Stops dispatching new issues
3. Stops sleeping between ticks
4. Waits for all running child processes to exit (polling every 100ms)
5. Logs final token usage totals
6. Exits with code 0

## Console Output

Symphony writes user-friendly output to stdout:

```
Symphony starting (github tracker)
  Workflow: WORKFLOW.md
  Log file: /path/to/symphony.log
  Press Ctrl+C to stop
Symphony orchestrator started
  Polling every 30000ms, max 10 concurrent agents
  Dispatching my-repo#42: Fix the login bug
    Edit app/Auth/LoginController.php
    Bash php artisan test --filter=LoginTest
    Result: success (45.2s, $0.1234)
  Completed my-repo#42
```

## Logging

Structured log output goes to `symphony.log` in `key=value` format:

```
ts=2026-03-30T12:00:00Z level=INFO msg="Symphony starting" workflow=WORKFLOW.md tracker=github
ts=2026-03-30T12:00:00Z level=INFO msg="Dispatching issue" issue_id=42 issue_identifier="my-repo#42"
ts=2026-03-30T12:00:00Z level=ERROR msg="Turn timeout reached" elapsed_ms=3600001 timeout_ms=3600000
```

Sensitive values (API keys, tokens, passwords, secrets) are automatically redacted from log output.
