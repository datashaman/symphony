# Process Model

Symphony uses POSIX process forking to run agents in isolated child processes. The parent process coordinates, and each child process handles a single issue.

## Forking

When the orchestrator dispatches an issue, it calls `pcntl_fork()`:

- **Parent** (fork returns child PID): Records the PID, marks the issue as running and claimed, continues the orchestration loop
- **Child** (fork returns 0): Runs the full agent workflow (workspace setup, hooks, prompt rendering, Claude Code execution), then calls `exit()` with the result code

If `pcntl_fork()` returns -1 (failure), the issue is skipped for this tick.

## Parent Responsibilities

The parent process never does heavy work. Its role is coordination:

1. **Track children**: Maintains a map of issue ID to `{pid, issue, startedAt, lastActivity}`
2. **Monitor exits**: On each tick, `pcntl_waitpid($pid, $status, WNOHANG)` checks each child without blocking
3. **Detect stalls**: Compares `hrtime()` against last activity timestamps
4. **Kill misbehaving workers**: `posix_kill($pid, SIGTERM)` for stalled or state-transitioned issues
5. **Manage retry queue**: Tracks failed issues with exponential backoff timing

## Child Responsibilities

Each child process:

1. **Create workspace**: `WorkspaceManager::create()` makes the directory and runs `after_create` hooks
2. **Render prompt**: `PromptBuilder::render()` fills the Twig template with issue data
3. **Run agent**: `ClaudeCodeRunner::run()` manages the multi-turn session
4. **Exit**: Code 0 on success, 1 on agent failure, 2 on unhandled exception

Children inherit the parent's file descriptors and logger. Each child's Claude Code process is launched via `proc_open` with separate stdin/stdout/stderr pipes.

## Signal Handling

Signals are registered in `RunCommand` before the orchestrator starts:

```
SIGINT  → orchestrator->requestShutdown()
SIGTERM → orchestrator->requestShutdown()
```

**Signal dispatch**: PHP requires explicit `pcntl_signal_dispatch()` calls to process pending signals. The orchestrator's sleep loop calls this every 100ms, ensuring prompt signal response.

**Shutdown sequence**:
1. Signal received, `requestShutdown()` sets the shutdown flag
2. The main loop exits after the current tick completes
3. `waitForChildren()` polls all running children every 100ms
4. Once all children have exited, the parent logs totals and exits with code 0

Children are not explicitly signaled during shutdown - they are allowed to complete naturally. If a child hangs, the parent will wait indefinitely.

## Worker Lifecycle

```
Eligible issue found
        │
        ▼
   pcntl_fork()
   ┌────┴────┐
   │         │
Parent     Child
   │         │
   │    Create workspace
   │         │
   │    Run after_create hooks
   │         │
   │    Render prompt
   │         │
   │    Launch Claude Code (multi-turn)
   │         │
   │    exit(0|1|2)
   │         │
   ▼         ▼
pcntl_waitpid detects exit
        │
        ▼
  exit code 0? ──yes──▶ Release slot, clear claimed
        │
        no
        │
        ▼
  Queue for retry (exponential backoff)
```

## Workspace Cleanup

Workspaces persist across retries - the same directory is reused if the issue is re-dispatched. Cleanup happens when:

- **Terminal state**: Issue moves to a terminal state while a worker is running. The parent kills the worker and calls `WorkspaceManager::remove()`, which runs `before_remove` hooks and recursively deletes the directory.
- **Startup cleanup**: When the orchestrator starts, `cleanupTerminal()` scans existing workspace directories and removes any whose issues are now in terminal states.
- **Non-active state**: If an issue moves to a non-active, non-terminal state, the worker is killed but the workspace is preserved (the issue may return to active later).

## Resource Limits

Concurrency is bounded by `agent.max_concurrent_agents`. The orchestrator counts running children and only dispatches new ones if slots are available. Each child process consumes:
- One OS process (fork)
- One workspace directory on disk
- One Claude Code subprocess (with its own memory footprint)
