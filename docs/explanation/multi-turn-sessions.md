# Multi-turn Agent Sessions

Symphony supports multi-turn Claude Code sessions where an agent can retry within the same worktree if the first attempt fails.

## How It Works

Each child process runs an agent session of up to `max_turns` turns:

### Turn 1: Initial Prompt

1. Build the full prompt from the Twig template with issue data
2. Launch Claude Code: `claude -p --verbose --output-format stream-json --dangerously-skip-permissions`
3. Pipe the rendered prompt to stdin
4. Parse streaming JSON output for session_id and token usage
5. Wait for the process to exit

If exit code is 0, the session is complete (success). If non-zero, proceed to turn 2.

### Turn 2+: Continuation

1. Launch Claude Code with `--continue` flag appended to the command
2. Do not send anything to stdin (continuation uses the existing session)
3. Parse output and wait for exit
4. If successful, stop. If failed and turns remain, wait 1 second and continue.

The `--continue` flag tells Claude Code to resume the previous session in the same working directory, preserving context from prior turns.

## Configuration

```yaml
agent:
  max_turns: 20  # default
```

The number of turns controls how many chances an agent gets within a single dispatch. This is separate from the retry queue, which re-dispatches the entire issue.

## Turn vs. Retry

| | Turn | Retry |
|---|------|-------|
| **Scope** | Within a single child process | New child process |
| **Worktree** | Same worktree, preserved state | Same worktree, preserved state |
| **Session** | Continuation (`--continue`) | Fresh prompt (with `attempt` variable) |
| **Delay** | 1 second | Exponential backoff (10s–300s) |
| **Prompt** | No new prompt | Full prompt re-rendered with `attempt` set |

A typical lifecycle:
1. Issue dispatched, child process starts
2. Turn 1 fails (e.g., tests don't pass)
3. Turns 2–5: continuation attempts, each building on prior work
4. If all turns fail, child exits with non-zero code
5. Parent queues issue for retry with backoff
6. On retry, a new child process starts with the full prompt (including `{% if attempt %}` block)

## Timeouts

Each turn has its own timeout enforcement:

- **Turn timeout** (`claude.turn_timeout_ms`): Maximum wall-clock time for a single Claude Code invocation (default: 1 hour)
- **Stall timeout** (`claude.stall_timeout_ms`): Maximum time without any stdout output (default: 5 minutes)

If either timeout fires, the Claude Code process is killed with SIGTERM and the turn counts as failed.

## Token Tracking

Token usage is accumulated across all turns in a session. The final result includes total `input_tokens` and `output_tokens` across all turns, plus the `session_id` from the last turn that reported one.

## Streaming JSON Parsing

Claude Code with `--output-format stream-json` emits newline-delimited JSON events. The runner parses these incrementally to extract:
- `session_id`: Identifies the session for continuation
- `usage.input_tokens` / `usage.output_tokens`: Token consumption
- Top-level `input_tokens` / `output_tokens`: Alternative token fields

The runner also logs agent activity:
- **Tool use**: Logs tool name and a summary (file path, command, pattern)
- **Text output**: Logs assistant text (truncated to 200 chars)
- **Results**: Logs subtype, duration, and cost
- Known event types (system, user, message, content_block_delta/stop, message_delta/stop, rate_limit_event) are silently ignored
- Unknown event types are logged as warnings
