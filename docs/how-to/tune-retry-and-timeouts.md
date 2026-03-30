# Tune Retry and Timeouts

Symphony has several timeout and retry settings that control how long agents run and how failures are handled.

## Agent Turns

An agent session consists of up to `max_turns` turns. The first turn sends the full prompt. Subsequent turns use `--continue` to resume the session.

```yaml
agent:
  max_turns: 20        # Maximum turns per issue (default: 20)
```

If an agent fails a turn, it retries on the next turn with a 1-second delay. If all turns are exhausted without success, the issue is queued for retry.

## Timeouts

### Turn Timeout

Maximum wall-clock time for a single Claude Code invocation:

```yaml
claude:
  turn_timeout_ms: 3600000  # 1 hour (default)
```

If exceeded, the agent process is killed (SIGTERM) and the turn counts as failed.

### Stall Timeout

Maximum time without any output from Claude Code:

```yaml
claude:
  stall_timeout_ms: 300000  # 5 minutes (default)
```

If Claude Code produces no stdout for this duration, it's considered stalled and killed.

### Hook Timeout

Maximum time for workspace hooks to complete:

```yaml
hooks:
  timeout_ms: 60000  # 1 minute (default)
```

If a hook exceeds this, it's killed. Fatal hooks (`after_create`, `before_run`) cause the child process to fail. Non-fatal hooks (`before_remove`) log a warning and continue.

## Retry Backoff

When an agent fails (non-zero exit or stall), the issue is placed in a retry queue with exponential backoff:

```
delay = min(10000ms * 2^(attempt-1), max_retry_backoff_ms)
```

| Attempt | Delay |
|---------|-------|
| 1 | 10 seconds |
| 2 | 20 seconds |
| 3 | 40 seconds |
| 4 | 80 seconds |
| 5 | 160 seconds |
| 6+ | 300 seconds (capped) |

Configure the cap:

```yaml
agent:
  max_retry_backoff_ms: 300000  # 5 minutes (default)
```

There is no maximum retry count - issues continue retrying until they succeed or their tracker state changes to terminal.

## Polling Interval

How often the orchestrator checks for new issues and reconciles running workers:

```yaml
polling:
  interval_ms: 30000  # 30 seconds (default)
```

The polling loop also handles signal dispatch (SIGINT/SIGTERM), so a very long interval delays graceful shutdown response. The sleep is broken into 100ms steps internally, so shutdown response time is at most 100ms regardless of interval.

## Concurrency

Maximum number of agent processes running simultaneously:

```yaml
agent:
  max_concurrent_agents: 10  # default
```

When all slots are full, new eligible issues wait until a slot opens on the next tick.

## Tuning Recommendations

- **Short-lived tasks**: Lower `max_turns` (5-10) and `turn_timeout_ms` (600000 / 10 min)
- **Complex tasks**: Keep defaults or increase `max_turns` (30+)
- **Flaky network**: Increase `stall_timeout_ms` to tolerate API latency
- **Rate-limited API**: Increase `polling.interval_ms` to reduce API calls
- **Resource-constrained host**: Lower `max_concurrent_agents` (2-3)
