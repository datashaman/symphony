## ADDED Requirements

### Requirement: Workspace path computation
The system SHALL compute workspace paths as `{root}/{workspace_key}` where `workspace_key` is the issue identifier with all characters not matching `[A-Za-z0-9._-]` replaced by `_`. The system SHALL verify via `realpath()` that the resolved path starts with `realpath($root)` to prevent path traversal.

#### Scenario: Normal path computation
- **WHEN** `pathForIssue()` is called for issue with identifier `"symphony#42"`
- **THEN** the workspace path is `"{root}/symphony_42"`

#### Scenario: Path traversal attempt
- **WHEN** an issue identifier resolves to a path containing `../` that would escape the workspace root
- **THEN** the system throws a security exception

### Requirement: Workspace lifecycle
The system SHALL support workspace creation (`create`) and removal (`remove`). Creation SHALL create the directory and run `after_create` hooks. Removal SHALL run `before_remove` hooks then recursively delete the directory.

#### Scenario: Create workspace
- **WHEN** `create()` is called for an issue
- **THEN** the directory is created at the computed path and `after_create` hooks are executed in that directory

#### Scenario: Remove workspace
- **WHEN** `remove()` is called for an issue
- **THEN** `before_remove` hooks run first, then the directory is recursively deleted

### Requirement: Hook execution
The system SHALL execute shell commands for lifecycle phases: `after_create`, `before_run`, `after_run`, `before_remove`. Hooks SHALL run with the workspace directory as cwd. Hooks SHALL timeout after `hooks.timeout_ms` (default 60000ms) enforced via `proc_open` and timer. `after_create` and `before_run` failures SHALL be fatal (abort the issue run). `after_run` and `before_remove` failures SHALL be logged and ignored.

#### Scenario: Hook timeout
- **WHEN** an `after_create` hook runs longer than `hooks.timeout_ms`
- **THEN** the hook process is killed and the system treats it as a fatal hook failure

#### Scenario: Fatal hook failure
- **WHEN** a `before_run` hook exits with non-zero status
- **THEN** the system aborts the issue run and logs the failure

#### Scenario: Non-fatal hook failure
- **WHEN** an `after_run` hook exits with non-zero status
- **THEN** the system logs the failure but continues normally

### Requirement: Startup cleanup of terminal issues
The system SHALL on startup scan for existing workspace directories, check their issue states via the tracker, and remove workspaces for issues in terminal states.

#### Scenario: Cleanup terminal workspace on startup
- **WHEN** the daemon starts and finds workspace directory `symphony_42` but issue 42 is in state `"done"`
- **THEN** the system removes the workspace directory after running `before_remove` hooks
