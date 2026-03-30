## ADDED Requirements

### Requirement: Parse WORKFLOW.md format
The system SHALL parse a WORKFLOW.md file consisting of YAML front matter delimited by `---` markers followed by a Markdown prompt body. The YAML front matter SHALL be parsed using Symfony YAML. The return value SHALL be an associative array with `config` (parsed YAML) and `prompt` (raw Markdown string) keys.

#### Scenario: Valid workflow file with front matter and prompt
- **WHEN** a WORKFLOW.md file contains valid YAML between `---` delimiters followed by Markdown text
- **THEN** the system returns `['config' => <parsed array>, 'prompt' => <markdown string>]`

#### Scenario: Missing front matter delimiters
- **WHEN** a WORKFLOW.md file has no `---` delimiters
- **THEN** the system throws an exception indicating invalid workflow format

#### Scenario: Empty prompt body
- **WHEN** a WORKFLOW.md file has valid YAML front matter but no content after the closing `---`
- **THEN** the system throws an exception indicating missing prompt template

#### Scenario: Invalid YAML in front matter
- **WHEN** the YAML between `---` delimiters is malformed
- **THEN** the system throws an exception with the YAML parse error

### Requirement: Dynamic reload on each poll tick
The system SHALL re-read and re-parse the WORKFLOW.md file on every poll tick, allowing configuration and prompt changes without restarting the daemon.

#### Scenario: Config changed between poll ticks
- **WHEN** the WORKFLOW.md file is modified while the daemon is running
- **THEN** the next poll tick uses the updated config and prompt values

#### Scenario: File becomes unreadable
- **WHEN** the WORKFLOW.md file is deleted or permissions are changed between ticks
- **THEN** the system logs an error and skips the tick without crashing
