# Orchestration Loop

The orchestrator runs an infinite loop where each iteration (called a "tick") performs a fixed sequence of phases. Between ticks, the daemon sleeps for the configured polling interval.

## Tick Phases

### 1. Reconcile

Check the status of all running child processes:

- **Non-blocking wait**: `pcntl_waitpid` with `WNOHANG` checks if each child has exited without blocking
- **Exit handling**: If a child exited with code 0, mark as success and release the claimed slot. If non-zero, queue for retry with exponential backoff
- **Stall detection**: Compare `hrtime` since last activity against `stall_timeout_ms`. Stalled workers are killed with SIGTERM and queued for retry
- **State refresh**: For still-running workers, query the tracker for current issue states:
  - **Terminal state**: Kill the worker and remove the workspace
  - **Non-active, non-terminal**: Kill the worker but preserve the workspace (the issue may return to active)
  - **Still active**: No action

### 2. Config Reload

Re-read the workflow file via `WorkflowLoader::load()`. This enables live configuration changes without restarting the daemon. If the reload fails (file removed, invalid YAML), the error is logged and the tick continues with the previous config.

### 3. Fetch Candidates

Query the tracker for issues in active states. The tracker handles pagination internally and returns the full list of candidate issues.

### 4. Sort

Sort candidates by three keys:
1. **Priority** ascending (lower number = higher priority, null = lowest)
2. **Created date** ascending (older issues first)
3. **Identifier** ascending (alphabetical tiebreaker)

This ensures high-priority, older issues are worked on first.

### 5. Filter

Remove ineligible issues from the sorted list:
- **Already running**: Issue has an active child process
- **Claimed**: Issue was recently dispatched (prevents re-dispatch before the child starts)
- **In retry backoff**: Issue failed and its retry delay hasn't elapsed
- **Blocked**: Issue has `blockedBy` references to issues that are still in active states

### 6. Dispatch

For each eligible issue, up to `max_concurrent_agents - running_count`:
1. `pcntl_fork()` creates a child process
2. **Child**: creates workspace, runs hooks, renders prompt, launches Claude Code, exits with result code
3. **Parent**: records the child PID, marks the issue as running and claimed

### 7. Process Retry Queue

Check the retry queue for items whose delay has elapsed. Expired items are removed from the queue and their claimed status is cleared, making them eligible for dispatch on the next tick.

## Sleep

Between ticks, the daemon sleeps for `polling.interval_ms`. The sleep is broken into 100ms steps with `pcntl_signal_dispatch()` calls, so:
- SIGINT/SIGTERM are handled promptly (within 100ms)
- Shutdown can interrupt the sleep early

## Startup

Before the first tick, the orchestrator runs `cleanupTerminal`: it scans existing workspace directories, checks their issues' tracker states, and removes workspaces for issues that have moved to terminal states since the last run.

## Shutdown

When `requestShutdown()` is called (via signal handler):
1. The `shutdown` flag is set
2. The main loop exits after the current tick
3. `waitForChildren()` blocks until all running child processes have exited (polling every 100ms)
4. Final token usage totals are logged
