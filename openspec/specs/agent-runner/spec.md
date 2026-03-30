## ADDED Requirements

### Requirement: Launch Claude Code via proc_open
The system SHALL launch the Claude Code CLI using `proc_open()` with the configured `claude.command` (default: `claude -p --output-format stream-json --worktree`). The working directory SHALL be the workspace path. The prompt SHALL be written to stdin, then stdin SHALL be closed. Stdout SHALL be read line-by-line as streaming JSON.

#### Scenario: Normal agent launch
- **WHEN** the agent runner is invoked with a prompt and workspace path
- **THEN** `proc_open` launches the configured command in the workspace directory, writes the prompt to stdin, closes stdin, and reads stdout

#### Scenario: Stderr capture
- **WHEN** the Claude Code process writes to stderr
- **THEN** the output is captured and logged for error diagnostics

### Requirement: Streaming JSON output parsing
The system SHALL parse each line of stdout as a JSON object. The system SHALL track `session_id`, token counts (input/output), and last activity timestamp from the stream events.

#### Scenario: Parse token usage from stream
- **WHEN** a JSON line contains token usage information
- **THEN** the system accumulates input_tokens and output_tokens

#### Scenario: Unknown event type
- **WHEN** a JSON line contains an unrecognized event type
- **THEN** the system logs it at debug level and continues without error

### Requirement: Multi-turn support
The system SHALL support multi-turn agent sessions. The first turn uses the full rendered prompt. Continuation turns SHALL use the configured command with `--continue` appended. The system SHALL execute up to `agent.max_turns` turns per issue run.

#### Scenario: Continuation after first turn
- **WHEN** the first turn completes normally and the issue still needs work
- **THEN** the system launches a continuation turn with the `--continue` flag

#### Scenario: Max turns reached
- **WHEN** the agent has executed `max_turns` turns for an issue
- **THEN** the system stops and returns the result without starting another turn

### Requirement: Timeout enforcement
The system SHALL enforce `claude.turn_timeout_ms` (kill if turn exceeds limit) and `claude.stall_timeout_ms` (kill if no output received within window). Timeouts SHALL be implemented via non-blocking reads and `hrtime()` monotonic clock checks.

#### Scenario: Turn timeout
- **WHEN** a turn runs longer than `turn_timeout_ms`
- **THEN** the process is killed via SIGTERM and the turn is marked as failed

#### Scenario: Stall timeout
- **WHEN** no stdout output is received for longer than `stall_timeout_ms`
- **THEN** the process is killed via SIGTERM and the turn is marked as stalled

### Requirement: Return structured result
The system SHALL return an associative array with `success` (bool), `tokens` (array with `input_tokens` and `output_tokens`), and `session_id` (string|null).

#### Scenario: Successful run result
- **WHEN** the Claude Code process exits with code 0
- **THEN** the result has `success: true` with accumulated token counts and session_id
