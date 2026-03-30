# Symphony

PHP orchestration daemon for coding agents, built on Laravel Zero. Polls issue trackers (GitHub, Jira), creates isolated git worktrees per issue, and dispatches Claude Code agent processes with multi-turn support and retry logic.

## Project Structure

```
app/
  Agent/ClaudeCodeRunner.php      # Multi-turn Claude Code invocation, streaming JSON parsing
  Commands/RunCommand.php         # CLI entry point: ./application run [workflow.yml]
  Config/
    StageConfig.php               # Pipeline stage value object (name, trigger, command, timeouts)
    WorkflowConfig.php            # YAML config parsing, env var resolution, pipeline stages
  Logging/StructuredFormatter.php # key=value structured log format with secret redaction
  Orchestrator/Orchestrator.php   # Main loop: polling, forking, reconciliation, retries, pipeline dispatch
  Prompt/PromptBuilder.php        # Twig template rendering for issue prompts
  Tracker/
    TrackerInterface.php          # Contract for issue fetchers
    GitHubTracker.php             # GitHub Issues REST API
    JiraTracker.php               # Jira REST API v3
    Issue.php                     # Normalized issue DTO
  Workflow/WorkflowLoader.php     # Pure YAML workflow parser
  Workspace/WorkspaceManager.php  # Workspace lifecycle (git worktrees), setup commands, cleanup
```

## Development Commands

```bash
# Run tests
./vendor/bin/pest

# Run linter
./vendor/bin/pint

# Run the daemon
./application run workflow.yml
```

## Key Conventions

- PHP 8.4+ with strict typing
- PSR-4 autoloading under `App\` namespace
- Tests in `tests/Unit/` and `tests/Feature/` using Pest
- Workflow files are pure YAML; stage prompts are inline under `pipeline.stages[].prompt`
- Environment variables in config use `$VAR` or `${VAR}` syntax, resolved at config load time
- Structured logging: `key=value` format to stderr via Monolog
- Process model: `pcntl_fork` per issue, parent monitors via non-blocking `pcntl_waitpid`
- Graceful shutdown: SIGINT/SIGTERM sets shutdown flag, waits for running workers

## Configuration

Workflow files (e.g., `workflow.yml`) are pure YAML with these sections:
- `tracker` - kind (github/jira), credentials, repository (optional, auto-detected from git remote for GitHub), terminal_states, active_states (Jira only), assignee/sprint/jql (Jira)
- `polling` - interval_ms (default: 30000)
- `workspace` - root path, setup commands (array), setup_timeout_ms (default: 60000)
- `agent` - max_concurrent_agents (default: 10), max_turns (default: 20), max_retry_backoff_ms (default: 300000)
- `claude` (optional) - command (default: `claude -p --verbose --output-format stream-json --dangerously-skip-permissions`), turn_timeout_ms (default: 3600000), stall_timeout_ms (default: 300000)
- `pipeline` (optional) - stages array for multi-agent workflows; each stage has name, trigger label, prompt, and optional claude overrides
- `prompt` (required for non-pipeline workflows) - Twig template for the agent prompt

## When Updating Documentation

If you change behavior, configuration keys, CLI arguments, or the Issue DTO, update the corresponding file in `docs/` and this file.
