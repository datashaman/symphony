## ADDED Requirements

### Requirement: In-memory state tracking
The system SHALL maintain orchestrator state in memory using: `running` (map of issueId to worker state), `claimed` (set of claimed issue IDs), `retryQueue` (map of issueId to retry state with attempt count, due time, and error), and `codexTotals` (accumulated input_tokens, output_tokens, seconds).

#### Scenario: State initialized on startup
- **WHEN** the orchestrator starts
- **THEN** all state maps are empty and codexTotals are zeroed

### Requirement: Poll tick sequence
Each poll tick SHALL execute in order: (1) reconcile running issues, (2) validate/reload dispatch config, (3) fetch candidates from tracker, (4) sort by priority ASC then createdAt ASC then identifier ASC, (5) filter eligible issues (not running, not claimed, concurrency slots available, no active blockers), (6) dispatch each eligible issue while slots remain, (7) sleep until next tick.

#### Scenario: Full poll tick with dispatch
- **WHEN** a tick fires with 2 candidate issues, both eligible, and 5 available concurrency slots
- **THEN** both issues are dispatched and worker processes are spawned

#### Scenario: Concurrency limit enforced
- **WHEN** `max_concurrent_agents` is 2 and 2 workers are already running
- **THEN** no new issues are dispatched even if candidates are available

#### Scenario: Priority ordering
- **WHEN** candidates include issue A (priority 2, created Jan 1) and issue B (priority 1, created Jan 2)
- **THEN** issue B is dispatched first (lower priority number = higher priority)

#### Scenario: Blocker filtering
- **WHEN** a candidate issue in "Todo" state has `blockedBy: ["42"]` and issue 42 is still in an active state
- **THEN** the blocked issue is skipped for dispatch

### Requirement: Process-based dispatch via pcntl_fork
The system SHALL dispatch each issue by forking a child process via `pcntl_fork()`. The child process SHALL: create/verify workspace, build prompt, run Claude Code agent, then exit. The parent SHALL track child PIDs in the running map and check child status via non-blocking `pcntl_waitpid(WNOHANG)` during reconciliation.

#### Scenario: Child process lifecycle
- **WHEN** an issue is dispatched
- **THEN** a child process is forked that creates the workspace, renders the prompt, runs the agent, and exits with appropriate status code

#### Scenario: Parent tracks children
- **WHEN** a child process is forked
- **THEN** the parent records the child PID and issue ID in the running map

#### Scenario: Child completion detected
- **WHEN** `pcntl_waitpid` returns a terminated child PID
- **THEN** the parent removes it from running and processes the exit status

### Requirement: Retry with exponential backoff
Continuation retries (normal exit, more turns needed) SHALL use a fixed 1000ms delay. Failure retries SHALL use `min(10000 * 2^(attempt-1), max_retry_backoff_ms)` exponential backoff. Due times SHALL be tracked using `hrtime(true)` (monotonic clock).

#### Scenario: Continuation retry delay
- **WHEN** an agent turn completes normally but needs continuation
- **THEN** the issue is queued for retry with a 1000ms delay

#### Scenario: Exponential backoff on failure
- **WHEN** an agent fails on attempt 3
- **THEN** the retry delay is `min(10000 * 2^2, max_retry_backoff_ms)` = `min(40000, 300000)` = 40000ms

#### Scenario: Backoff cap
- **WHEN** an agent fails on attempt 10
- **THEN** the retry delay is capped at `max_retry_backoff_ms` (300000ms)

### Requirement: Reconciliation
During reconciliation, the system SHALL: (1) detect stalled workers where `elapsed > stall_timeout_ms` since last activity and kill them, (2) fetch current tracker states for running issue IDs, (3) kill and remove workspace for issues in terminal states, (4) kill (no cleanup) for issues in non-active non-terminal states, (5) update in-memory snapshots for still-active issues.

#### Scenario: Stall detection kills worker
- **WHEN** a worker has had no activity for longer than `stall_timeout_ms`
- **THEN** the child process is killed and the issue is released

#### Scenario: Issue moved to terminal state externally
- **WHEN** reconciliation finds a running issue now in state `"Done"`
- **THEN** the child process is killed and the workspace is removed

#### Scenario: Issue moved to non-active non-terminal state
- **WHEN** reconciliation finds a running issue now in a state that is neither active nor terminal
- **THEN** the child process is killed but the workspace is preserved

### Requirement: Graceful shutdown
The system SHALL register signal handlers for SIGINT and SIGTERM. On signal receipt, the system SHALL set a shutdown flag, wait for all child processes to complete, then exit with code 0.

#### Scenario: SIGINT graceful shutdown
- **WHEN** SIGINT is received while workers are running
- **THEN** no new dispatches occur, existing workers finish, and the process exits 0
