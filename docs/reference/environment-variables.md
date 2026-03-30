# Environment Variables

Symphony resolves environment variables referenced in workflow YAML configuration. Variables use `$VAR` or `${VAR}` syntax and are resolved at config load time.

If a referenced variable is not set, Symphony throws an error at startup.

## GitHub Tracker

| Variable | Description | Example |
|----------|-------------|---------|
| `GITHUB_TOKEN` | GitHub personal access token with `repo` scope | `ghp_xxxxxxxxxxxx` |

## Jira Tracker

| Variable | Description | Example |
|----------|-------------|---------|
| `JIRA_API_TOKEN` | Jira API token | `xxxxxxxxxxxxxxxx` |
| `JIRA_EMAIL` | Email for Jira API authentication | `you@example.com` |
| `JIRA_BASE_URL` | Jira Cloud instance base URL | `https://your-domain.atlassian.net` |

## Usage in Workflow Files

Reference variables in any string value in the YAML configuration:

```yaml
tracker:
  api_key: $GITHUB_TOKEN
  endpoint: ${JIRA_BASE_URL}
```

Both `$VAR` and `${VAR}` syntaxes are supported. The variable name must match `[A-Za-z_][A-Za-z0-9_]*`.

## Setting Variables

Set variables in your shell environment before running Symphony:

```bash
export GITHUB_TOKEN=ghp_xxxxxxxxxxxx
./application run WORKFLOW.md
```

Or use a `.env` file with a tool like [direnv](https://direnv.net/) or source it manually:

```bash
source .env
./application run WORKFLOW.md
```

Note: Symphony does not read `.env` files directly. You must load them into the shell environment yourself.
