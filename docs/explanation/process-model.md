# Process Model

Symphony uses POSIX process forking to run agents in isolated child processes. The parent process coordinates, and each child process handles a single issue.

## Forking

When the orchestrator dispatches an issue, it calls `pcntl_fork()`:

- **Parent** (fork returns child PID): Records the PID, marks the issue as running and claimed, continues the orchestration loop
- **Child** (fork returns 0): Runs the full agent workflow (worktree setup, prompt rendering, Claude Code execution), then calls `exit()` with the result code

If `pcntl_fork()` returns -1 (failure), the issue is skipped for this tick.

## Parent Responsibilities

The parent process never does heavy work. Its role is coordination:

1. **Track children**: Maintains a map of issue ID to `{pid, issue, startedAt, lastActivity}`
2. **Monitor exits**: On each tick, `pcntl_waitpid($pid, $status, WNOHANG)` checks each child without blocking
3. **Detect stalls**: Compares `hrtime()` against last activity timestamps
4. **Kill misbehaving workers**: `posix_kill($pid, SIGTERM)` for stalled or state-transitioned issues
5. **Manage retry queue**: Tracks failed issues with exponential backoff timing
6. **Manage claimed set**: Keeps completed issues claimed to prevent re-dispatch until terminal

## Child Responsibilities

Each child process:

1. **Create worktree**: `WorkspaceManager::create()` creates a git worktree and runs setup commands
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

Children are not explicitly signaled during shutdown — they are allowed to complete naturally. If a child hangs, the parent will wait indefinitely.

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
   │    Create git worktree
   │         │
   │    Run setup commands
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
  exit code 0? ──yes──▶ Keep claimed, release running slot
        │
        no
        │
        ▼
  Queue for retry (exponential backoff)
```

## Worktree Lifecycle

Worktrees persist across retries — the same directory is reused if the issue is re-dispatched. Setup commands only run on initial creation, not on reuse. Cleanup happens when:

- **Terminal state**: Issue moves to a terminal state while a worker is running. The parent kills the worker and calls `WorkspaceManager::remove()`, which removes the worktree and deletes the branch.
- **Startup cleanup**: When the orchestrator starts, `cleanupTerminal()` prunes stale worktrees and removes any whose issues are now in terminal states.
- **Non-active state**: If an issue moves to a non-active, non-terminal state, the worker is killed but the worktree is preserved (the issue may return to active later).

## Worktree Path Security

The WorkspaceManager validates that computed workspace paths don't escape the root directory. It uses `realpath()` to resolve symlinks and `str_starts_with()` to ensure the path remains under the configured root. A `RuntimeException` is thrown on path traversal attempts.

## Resource Limits

Concurrency is bounded by `agent.max_concurrent_agents`. The orchestrator counts running children and only dispatches new ones if slots are available. Each child process consumes:
- One OS process (fork)
- One git worktree on disk
- One Claude Code subprocess (with its own memory footprint)
