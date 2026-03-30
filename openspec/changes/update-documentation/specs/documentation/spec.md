## ADDED Requirements

### Requirement: Project README
The project SHALL have a README.md that describes Symphony's purpose, prerequisites, installation steps, quick-start usage, and links to detailed documentation. The README SHALL NOT contain default framework boilerplate.

#### Scenario: Developer discovers the project
- **WHEN** a developer opens the repository for the first time
- **THEN** README.md explains that Symphony is a PHP orchestration daemon for coding agents, lists PHP 8.4+ and required extensions as prerequisites, and provides a quick-start command (`./application run WORKFLOW.md`)

#### Scenario: README links to detailed docs
- **WHEN** a developer wants to learn more after reading the README
- **THEN** the README contains links to docs/tutorial/, docs/how-to/, docs/reference/, and docs/explanation/

### Requirement: CLAUDE.md project context
The project SHALL have a CLAUDE.md at the repository root containing architecture summary, coding conventions, test commands, and development workflow guidelines.

#### Scenario: AI coding session starts
- **WHEN** an AI assistant begins a coding session in this repository
- **THEN** CLAUDE.md provides enough context to understand the project structure, run tests, and follow coding conventions without reading source code first

#### Scenario: CLAUDE.md includes test commands
- **WHEN** an AI assistant needs to verify changes
- **THEN** CLAUDE.md lists the exact commands to run unit tests and any linting tools

### Requirement: Environment variable example file
The project SHALL have a .env.example file documenting all environment variables used by workflow configurations and tracker integrations, with descriptive comments and placeholder values.

#### Scenario: Developer sets up environment
- **WHEN** a developer copies .env.example to .env
- **THEN** all required environment variables are listed with comments explaining their purpose and example values

#### Scenario: All tracker variables documented
- **WHEN** a developer configures either the GitHub or Jira tracker
- **THEN** .env.example contains the relevant API token and endpoint variables for both trackers

### Requirement: Tutorial documentation
The project SHALL include a getting-started tutorial at docs/tutorial/getting-started.md that walks a new user through running Symphony against a GitHub repository end-to-end.

#### Scenario: New user follows tutorial
- **WHEN** a new user follows the getting-started tutorial from start to finish
- **THEN** they have a running Symphony instance polling a GitHub repository for issues and dispatching Claude Code agents

### Requirement: How-to guides
The project SHALL include task-oriented how-to guides covering: GitHub tracker configuration, Jira tracker configuration, writing workflow templates, tuning retry and timeout settings, and configuring workspace hooks.

#### Scenario: User configures Jira tracker
- **WHEN** a user wants to use Symphony with Jira instead of GitHub
- **THEN** docs/how-to/configure-jira-tracker.md provides step-by-step instructions including JQL configuration, API token setup, and state mapping

#### Scenario: User customizes retry behavior
- **WHEN** a user wants to change retry limits or backoff timing
- **THEN** docs/how-to/tune-retry-and-timeouts.md explains the available configuration keys, their defaults, and the effect of each setting

### Requirement: Reference documentation
The project SHALL include reference documentation covering: full configuration schema with types and defaults, CLI arguments, environment variables, and the Issue DTO field list.

#### Scenario: User looks up configuration key
- **WHEN** a user needs to know all valid keys under the `agent:` configuration section
- **THEN** docs/reference/configuration.md lists every key, its type, default value, and description

#### Scenario: User checks Issue DTO fields
- **WHEN** a developer writing a workflow template needs to know available issue fields
- **THEN** docs/reference/issue-dto.md lists all fields on the Issue object with types and descriptions

### Requirement: Explanation documentation
The project SHALL include explanation documents covering: system architecture, the orchestration loop, multi-turn agent sessions, and the process model (forking, signals, cleanup).

#### Scenario: Developer studies architecture
- **WHEN** a developer wants to understand how Symphony's components fit together
- **THEN** docs/explanation/architecture.md describes the component responsibilities and their interactions

#### Scenario: Developer understands orchestration tick
- **WHEN** a developer wants to understand what happens on each polling tick
- **THEN** docs/explanation/orchestration-loop.md describes the reconciliation, config reload, candidate fetch, sort/filter, and dispatch phases

### Requirement: Documentation structure follows Diataxis
The docs/ directory SHALL be organized into four subdirectories matching the Diataxis framework: tutorial/, how-to/, reference/, explanation/.

#### Scenario: Documentation directory layout
- **WHEN** a user browses the docs/ directory
- **THEN** they find tutorial/, how-to/, reference/, and explanation/ subdirectories, each containing relevant documentation files
