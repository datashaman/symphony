## ADDED Requirements

### Requirement: Typed config with spec defaults
The system SHALL provide typed getters for all configuration values with the following defaults:
- `tracker.kind`: required, no default (must be `"github"` or `"jira"`)
- `tracker.api_key`: required, supports `$ENV_VAR` resolution
- `tracker.active_states`: default `["Todo", "In Progress"]`
- `tracker.terminal_states`: default `["Closed", "Cancelled", "Canceled", "Duplicate", "Done"]`
- `polling.interval_ms`: default `30000`
- `workspace.root`: default `sys_get_temp_dir() . '/symphony_workspaces'`
- `agent.max_concurrent_agents`: default `10`
- `agent.max_turns`: default `20`
- `agent.max_retry_backoff_ms`: default `300000`
- `claude.command`: default `"claude -p --output-format stream-json --worktree"`
- `claude.turn_timeout_ms`: default `3600000`
- `claude.stall_timeout_ms`: default `300000`

#### Scenario: All defaults applied
- **WHEN** config YAML provides only `tracker.kind: github` and `tracker.api_key: $GITHUB_TOKEN`
- **THEN** all other values use their documented defaults

#### Scenario: Override defaults
- **WHEN** config YAML specifies `polling.interval_ms: 60000`
- **THEN** the getter returns `60000` while all other values remain at defaults

### Requirement: Environment variable resolution
The system SHALL resolve `$VAR` and `${VAR}` patterns in string config values by calling `getenv('VAR')`. Resolution SHALL occur at config load time.

#### Scenario: Simple $VAR resolution
- **WHEN** config contains `api_key: $GITHUB_TOKEN` and `GITHUB_TOKEN=ghp_abc123` is set in the environment
- **THEN** the resolved value is `"ghp_abc123"`

#### Scenario: Braced ${VAR} resolution
- **WHEN** config contains `api_key: ${GITHUB_TOKEN}`
- **THEN** the resolved value is the same as `getenv('GITHUB_TOKEN')`

#### Scenario: Unset environment variable
- **WHEN** config references `$NONEXISTENT_VAR` and that variable is not set
- **THEN** the system throws a validation exception

### Requirement: Config validation
The system SHALL validate at load time that:
- `tracker.kind` is one of the supported tracker types
- `tracker.api_key` is present and resolves to a non-empty value
- `claude.command` has a sensible default and is not required

#### Scenario: Unsupported tracker kind
- **WHEN** config specifies `tracker.kind: linear`
- **THEN** the system throws a validation exception listing supported kinds

#### Scenario: Missing API key
- **WHEN** `tracker.api_key` is absent from config
- **THEN** the system throws a validation exception indicating the required field
