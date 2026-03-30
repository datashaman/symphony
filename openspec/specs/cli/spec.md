## ADDED Requirements

### Requirement: Run command entry point
The system SHALL provide a Laravel Zero command `run {workflow=./WORKFLOW.md}` that accepts an optional workflow file path (default: `./WORKFLOW.md`). The command SHALL load the workflow, build config, create the tracker, and start the orchestrator loop.

#### Scenario: Default workflow file
- **WHEN** `symphony run` is invoked with no arguments
- **THEN** the system loads `./WORKFLOW.md` from the current working directory

#### Scenario: Custom workflow path
- **WHEN** `symphony run /path/to/custom-workflow.md` is invoked
- **THEN** the system loads the specified file

#### Scenario: Missing workflow file
- **WHEN** the specified workflow file does not exist
- **THEN** the command exits with non-zero status and an error message

### Requirement: Exit codes
The command SHALL exit with code 0 on graceful shutdown (SIGINT/SIGTERM) and non-zero on startup failure (invalid config, missing file, unsupported tracker).

#### Scenario: Graceful shutdown exit
- **WHEN** SIGINT is received and all workers complete
- **THEN** the command exits with code 0

#### Scenario: Startup failure exit
- **WHEN** the WORKFLOW.md contains an unsupported tracker kind
- **THEN** the command exits with non-zero status

### Requirement: Signal handler registration
The command SHALL register `pcntl_signal` handlers for SIGINT and SIGTERM before entering the orchestrator loop.

#### Scenario: Signal handlers active
- **WHEN** the orchestrator loop is running
- **THEN** SIGINT and SIGTERM are caught by registered handlers rather than causing immediate termination
