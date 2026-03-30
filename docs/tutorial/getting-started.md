# Getting Started with Symphony

This tutorial walks you through running Symphony against a GitHub repository. By the end, you'll have a daemon polling for issues and dispatching Claude Code agents to work on them.

## Prerequisites

- PHP 8.4+ with `pcntl` and `posix` extensions
- [Composer](https://getcomposer.org/)
- [Claude Code CLI](https://claude.ai/claude-code) installed and authenticated (`claude` available in your PATH)
- A GitHub personal access token with `repo` scope
- A GitHub repository with open issues

## Step 1: Install Symphony

```bash
git clone https://github.com/datashaman/symphony.git
cd symphony
composer install
```

## Step 2: Set Up Environment Variables

```bash
cp .env.example .env
```

Edit `.env` and set your GitHub token:

```
GITHUB_TOKEN=ghp_your_actual_token_here
```

## Step 3: Configure the Workflow

Edit `WORKFLOW.md` and update the `tracker` section to point to your repository:

```yaml
---
tracker:
  kind: github
  repository: your-username/your-repo
  api_key: $GITHUB_TOKEN
  active_states:
    - todo
    - in-progress
  terminal_states:
    - done
    - closed
    - cancelled

polling:
  interval_ms: 30000

workspace:
  setup:
    - "cp %BASE%/.env .env"

agent:
  max_concurrent_agents: 5
  max_turns: 20
---

You are working on issue {{ issue.identifier }}: {{ issue.title }}

{{ issue.description }}

{% if attempt %}
This is retry attempt {{ attempt }}. Review what was done previously and continue from where you left off.
{% endif %}
```

Key things to change:
- `tracker.repository` — your GitHub `owner/repo`
- `active_states` — the issue labels/states that Symphony should pick up (case-insensitive)

## Step 4: Create a Test Issue

Create an issue on your GitHub repository. Make sure it has a label that matches one of your `active_states` (e.g., "todo").

## Step 5: Run Symphony

```bash
./application run WORKFLOW.md
```

You'll see console output and structured logs:

```
Symphony starting (github tracker)
  Workflow: WORKFLOW.md
  Log file: /path/to/symphony.log
  Press Ctrl+C to stop
Symphony orchestrator started
  Polling every 30000ms, max 5 concurrent agents
  Dispatching my-repo#1: Fix the login bug
```

## Step 6: Stop the Daemon

Press `Ctrl+C` to initiate graceful shutdown. Symphony will:
1. Stop accepting new issues
2. Wait for running agents to finish their current turn
3. Exit cleanly

You can also send `SIGTERM` for the same behavior:

```bash
kill $(pgrep -f "application run")
```

## What Happens Under the Hood

1. Symphony reads `WORKFLOW.md` and resolves environment variables (`$GITHUB_TOKEN` becomes your actual token)
2. Configured state labels are auto-created on the GitHub repository if missing
3. On each tick (every 30 seconds by default), it queries the GitHub API for issues matching `active_states`
4. For each eligible issue, it forks a child process that:
   - Creates a git worktree for isolation
   - Runs workspace setup commands (e.g., copying `.env`)
   - Renders the Twig prompt template with issue data
   - Launches Claude Code with the rendered prompt
   - If the agent fails, retries with `--continue` up to `max_turns` times
5. The parent process monitors children, kills stalled workers, and retries failed issues with exponential backoff

## Next Steps

- [Configure Jira Tracker](../how-to/configure-jira-tracker.md) if you use Jira instead of GitHub
- [Write Workflow Templates](../how-to/write-workflow-templates.md) for more advanced prompt templates
- [Tune Retry and Timeouts](../how-to/tune-retry-and-timeouts.md) to adjust agent behavior
- [Configuration Reference](../reference/configuration.md) for all available settings
