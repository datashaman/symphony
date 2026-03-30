# Symphony

A PHP orchestration daemon that coordinates coding agents against project issues. Symphony polls issue trackers (GitHub Issues, Jira), creates isolated workspaces, and dispatches [Claude Code](https://claude.ai/claude-code) agents to work on each issue with multi-turn support, retry logic, and resource constraints.

## Prerequisites

- PHP 8.4+
- Extensions: `pcntl`, `posix`
- [Composer](https://getcomposer.org/)
- [Claude Code CLI](https://claude.ai/claude-code) installed and authenticated

## Installation

```bash
git clone https://github.com/datashaman/symphony.git
cd symphony
composer install
```

## Quick Start

1. Copy the example environment file and fill in your tokens:

   ```bash
   cp .env.example .env
   # Edit .env with your API tokens
   ```

2. Edit `WORKFLOW.md` (or create your own) with your tracker config and prompt template. See [WORKFLOW.md](WORKFLOW.md) for a GitHub example or [WORKFLOW.jira.md](WORKFLOW.jira.md) for Jira.

3. Run the daemon:

   ```bash
   ./application run WORKFLOW.md
   ```

   Symphony will poll your issue tracker, create workspaces for eligible issues, and launch Claude Code agents to work on them.

4. Stop gracefully with `Ctrl+C` (sends SIGINT). Running agents will finish their current turn before the daemon exits.

## How It Works

1. **Poll** the issue tracker for issues in active states
2. **Sort** candidates by priority, creation date, and identifier
3. **Filter** out issues that are already running, claimed, blocked, or in retry backoff
4. **Fork** a child process per eligible issue (up to `max_concurrent_agents`)
5. **Each child**: creates a workspace, runs hooks, renders the prompt template, and launches Claude Code
6. **Multi-turn**: if an agent fails, it retries with `--continue` up to `max_turns` times
7. **Retry**: failed issues are re-queued with exponential backoff (10s * 2^attempt, capped at 5 min)
8. **Reconcile**: on each tick, check worker status, kill stalled processes, and handle state transitions

## Documentation

- **[Tutorial: Getting Started](docs/tutorial/getting-started.md)** - Run Symphony against a GitHub project end-to-end
- **How-to Guides**
  - [Configure GitHub Tracker](docs/how-to/configure-github-tracker.md)
  - [Configure Jira Tracker](docs/how-to/configure-jira-tracker.md)
  - [Write Workflow Templates](docs/how-to/write-workflow-templates.md)
  - [Tune Retry and Timeouts](docs/how-to/tune-retry-and-timeouts.md)
  - [Configure Workspace Hooks](docs/how-to/configure-workspace-hooks.md)
- **Reference**
  - [Configuration Schema](docs/reference/configuration.md)
  - [CLI Usage](docs/reference/cli.md)
  - [Environment Variables](docs/reference/environment-variables.md)
  - [Issue DTO](docs/reference/issue-dto.md)
- **Explanation**
  - [Architecture](docs/explanation/architecture.md)
  - [Orchestration Loop](docs/explanation/orchestration-loop.md)
  - [Multi-turn Sessions](docs/explanation/multi-turn-sessions.md)
  - [Process Model](docs/explanation/process-model.md)

## License

MIT
