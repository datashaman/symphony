## ADDED Requirements

### Requirement: Twig template rendering with strict variables
The system SHALL render the WORKFLOW.md prompt body using Twig with `StrictVariables` enabled. Template variables SHALL include `issue` (the full normalized issue as an associative array) and `attempt` (null for first attempt, integer for retries). DateTimeImmutable fields SHALL be converted to ISO-8601 strings before rendering.

#### Scenario: Render prompt with issue context
- **WHEN** the prompt template is `"Fix {{ issue.identifier }}: {{ issue.title }}"` and the issue has identifier `"symphony#42"` and title `"Fix login bug"`
- **THEN** the rendered output is `"Fix symphony#42: Fix login bug"`

#### Scenario: Retry attempt variable
- **WHEN** the prompt includes `"{% if attempt %}Retry attempt {{ attempt }}{% endif %}"` and this is the 3rd attempt
- **THEN** the rendered output includes `"Retry attempt 3"`

#### Scenario: First attempt has null attempt
- **WHEN** the prompt includes an attempt conditional and this is the first attempt
- **THEN** the `attempt` variable is null and the conditional block is not rendered

### Requirement: Undefined variable error
The system SHALL throw an exception when the prompt template references an undefined variable, due to Twig's strict mode.

#### Scenario: Undefined variable in template
- **WHEN** the prompt template references `{{ issue.nonexistent_field }}` and that field does not exist
- **THEN** the system throws a rendering exception

### Requirement: DateTime to string conversion
The system SHALL convert all DateTimeImmutable values in the issue array to ISO-8601 strings before passing to Twig.

#### Scenario: DateTime fields rendered as strings
- **WHEN** the issue has `createdAt` as a DateTimeImmutable
- **THEN** the Twig template receives it as `"2025-01-15T15:00:00+00:00"` and renders correctly
