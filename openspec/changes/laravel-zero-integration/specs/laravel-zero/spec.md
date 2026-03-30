## ADDED Requirements

### Requirement: Dotenv environment loading
The system SHALL use the Laravel Zero dotenv component to automatically load `.env` files from the current working directory. Users SHALL NOT need to manually `source .env` before running Symphony. Environment variables from `.env` SHALL be available for resolution in workflow YAML configuration via `$VAR` and `${VAR}` syntax.

#### Scenario: Automatic .env loading
- **WHEN** a `.env` file exists in the current working directory containing `GITHUB_TOKEN=ghp_abc123`
- **THEN** the workflow config value `$GITHUB_TOKEN` resolves to `ghp_abc123` without any manual shell setup

#### Scenario: No .env file
- **WHEN** no `.env` file exists in the current working directory
- **THEN** the system starts normally and resolves environment variables from the shell environment only

#### Scenario: Shell environment takes precedence
- **WHEN** both `.env` and the shell environment define `GITHUB_TOKEN`
- **THEN** the shell environment value takes precedence

### Requirement: Laravel logging integration
The system SHALL use the Laravel Zero logging component with a configurable `config/logging.php` instead of manually wiring Monolog in the run command. The structured `key=value` formatter SHALL be configured as the default channel format. Log channels SHALL be configurable without code changes.

#### Scenario: Default logging channel
- **WHEN** Symphony starts with no logging overrides
- **THEN** structured `key=value` logs are written to stderr using the StructuredFormatter

#### Scenario: Custom logging configuration
- **WHEN** `config/logging.php` is modified to add a file channel
- **THEN** logs are written to the configured file path without code changes

### Requirement: HTTP client integration
The system SHALL use the Laravel Zero HTTP client component (Laravel's HTTP facade) for tracker API calls instead of raw Guzzle. This provides consistent retry, timeout, and testing support across all tracker implementations.

#### Scenario: GitHub API call via HTTP facade
- **WHEN** the GitHub tracker fetches issues
- **THEN** it uses `Http::withToken()->get()` rather than raw Guzzle client calls

#### Scenario: Jira API call via HTTP facade
- **WHEN** the Jira tracker fetches issues
- **THEN** it uses `Http::withBasicAuth()->get()` rather than raw Guzzle client calls

### Requirement: Standalone PHAR build
The system SHALL support building a standalone PHAR binary via `php application app:build symphony`. The built binary SHALL be distributable without requiring Composer or the source tree on the target machine.

#### Scenario: Build PHAR
- **WHEN** `php application app:build symphony` is executed
- **THEN** a `builds/symphony` PHAR file is produced that can be run directly

#### Scenario: PHAR loads .env
- **WHEN** the PHAR binary is run in a directory containing a `.env` file
- **THEN** environment variables are loaded from `.env` the same as when running from source

### Requirement: Self-update command
The system SHALL provide a `self-update` command that checks for and installs the latest version from GitHub releases. This SHALL be available only in the PHAR-built binary.

#### Scenario: Check for updates
- **WHEN** `symphony self-update` is executed
- **THEN** the system checks GitHub releases for a newer version and installs it if available

#### Scenario: Already up to date
- **WHEN** `symphony self-update` is executed and the current version is the latest
- **THEN** the system reports that no update is available
