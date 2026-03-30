## ADDED Requirements

### Requirement: Structured key=value log format
The system SHALL format log entries as `key=value` pairs using a custom Monolog formatter. Every log entry SHALL include `timestamp`, `level`, and `message` fields.

#### Scenario: Basic log entry format
- **WHEN** an info-level message `"issue dispatched"` is logged
- **THEN** the output follows the pattern `timestamp=<ISO-8601> level=info message="issue dispatched"`

### Requirement: Contextual fields
Log entries SHALL include `issue_id`, `issue_identifier`, and `session_id` when available in the logging context. The system SHALL propagate these fields automatically for all log entries within an issue's lifecycle.

#### Scenario: Issue context in logs
- **WHEN** logging occurs during processing of issue `symphony#42` with session `sess_abc`
- **THEN** the log entry includes `issue_id=42 issue_identifier="symphony#42" session_id=sess_abc`

#### Scenario: Missing optional fields
- **WHEN** logging occurs before an issue is claimed (no session_id)
- **THEN** the `session_id` field is omitted from the log entry

### Requirement: Secret redaction
The system SHALL never log API tokens or secret values. Config values resolved from environment variables SHALL not appear in log output.

#### Scenario: API key not logged
- **WHEN** the system logs tracker configuration details
- **THEN** the `api_key` value is redacted or omitted from the log entry
