# Write Workflow Templates

Workflow files combine YAML configuration with a Twig prompt template. This guide covers how to write and customize the prompt template section.

## File Format

A workflow file has two parts separated by `---` delimiters:

```
---
<YAML configuration>
---
<Twig prompt template>
```

The YAML section configures the tracker, polling, workspace, agent, and claude settings. The template below the second `---` is rendered for each issue before being sent to Claude Code.

## Available Template Variables

### `issue` Object

| Variable | Type | Description |
|----------|------|-------------|
| `issue.id` | string | Internal ID from the tracker |
| `issue.identifier` | string | Human-readable identifier (e.g., `my-repo#42`, `PROJ-123`) |
| `issue.title` | string | Issue title |
| `issue.description` | string | Issue body/description |
| `issue.priority` | int or null | Numeric priority (lower = higher priority) |
| `issue.state` | string | Current state label |
| `issue.branchName` | string | Git branch name (e.g., `symphony/my-repo__42`) |
| `issue.url` | string | Web URL for the issue |
| `issue.labels` | string[] | Lowercase label list |
| `issue.blockedBy` | string[] | IDs of blocking issues |
| `issue.createdAt` | string | ISO 8601 creation timestamp |
| `issue.updatedAt` | string | ISO 8601 last update timestamp |

### `attempt` Variable

| Variable | Type | Description |
|----------|------|-------------|
| `attempt` | int or null | Retry attempt number (null on first try) |

## Twig Syntax Basics

**Output a variable:**
```twig
{{ issue.title }}
```

**Conditional blocks:**
```twig
{% if attempt %}
This is retry attempt {{ attempt }}.
{% endif %}
```

**Iterate over labels:**
```twig
{% for label in issue.labels %}
- {{ label }}
{% endfor %}
```

## Examples

### Basic Template

```twig
You are working on issue {{ issue.identifier }}: {{ issue.title }}

{{ issue.description }}

{% if attempt %}
This is retry attempt {{ attempt }}. Review what was done previously and continue from where you left off.
{% endif %}
```

### Template with Context and Constraints

```twig
You are an expert software engineer working on {{ issue.identifier }}: {{ issue.title }}

## Issue Details
{{ issue.description }}

## Labels
{% for label in issue.labels %}
- {{ label }}
{% endfor %}

## Instructions
- Work on the branch `{{ issue.branchName }}`
- Write tests for your changes
- Make small, focused commits
- Do not modify unrelated files

{% if attempt %}
## Retry Context
This is retry attempt {{ attempt }}. The previous attempt failed.
Check the git log and test output to understand what happened, then continue.
{% endif %}
```

### Template with Priority-Based Instructions

```twig
{{ issue.identifier }}: {{ issue.title }}

{{ issue.description }}

{% if issue.priority and issue.priority <= 2 %}
HIGH PRIORITY: This issue needs immediate attention. Focus on a correct fix over a quick one.
{% endif %}

{% if 'bug' in issue.labels %}
This is a bug fix. Write a failing test first, then fix the bug.
{% endif %}
```

## Prime Directive

Include commit/push/PR instructions directly in your workflow template for stages where you want the agent to ship its work. For example:

```twig
## Prime Directive

After completing all work, you MUST commit, push, and open a pull request.

1. **Commit** all changes referencing {{ '{{' }} issue.identifier {{ '}}' }}.
2. **Push** the current branch.
3. **Create a pull request** with "Closes {{ '{{' }} issue.url | default(issue.identifier) {{ '}}' }}" in the body.
```

In multi-stage pipelines, only add this to the final stage (e.g., `implement`), not intermediate stages like `plan`.

## Tips

- The template uses Twig with `strict_variables: true` — referencing an undefined variable will error
- Autoescaping is disabled — output is plain text, not HTML
- DateTimes are converted to ISO 8601 strings before rendering
- Keep prompts focused: the more specific the instructions, the better the agent performs
- Use `{% if attempt %}` to give retry-specific guidance
