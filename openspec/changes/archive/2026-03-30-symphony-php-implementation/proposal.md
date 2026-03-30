## Why

OpenAI published the Symphony spec -- a long-running daemon that orchestrates coding agents against project issues. The reference implementation is Elixir/Phoenix. We need a PHP 8.4+ implementation using Laravel Zero that PHP teams can adopt, with GitHub Issues and Jira support (instead of Linear) and Claude Code as the coding agent (instead of Codex).

## What Changes

- New Laravel Zero CLI application (`symphony run [workflow-path]`) that implements the full Symphony spec
- WORKFLOW.md loader: parses YAML front matter + Twig prompt template
- Typed config layer with `$ENV` variable resolution and spec defaults
- GitHub Issues tracker (REST API, label-based state mapping)
- Jira tracker (REST API, native workflow states)
- Orchestrator with polling loop, priority-based dispatch, concurrency control, and exponential backoff retry
- Workspace manager with path sanitization, lifecycle hooks, and cleanup
- Claude Code agent runner via `proc_open` with streaming JSON output, multi-turn support, and timeout enforcement
- Twig-based prompt builder with strict variable handling
- Structured `key=value` logging via Monolog
- Process forking (`pcntl_fork`) for concurrent agent execution
- Graceful shutdown via SIGINT/SIGTERM signal handling

## Capabilities

### New Capabilities
- `workflow-loader`: Parse WORKFLOW.md files (YAML front matter + Twig prompt body) with dynamic reload
- `config`: Typed configuration layer with environment variable resolution, defaults, and validation
- `issue-tracking`: Normalized issue model and tracker interface with GitHub and Jira implementations
- `workspace`: Per-issue workspace lifecycle management with path safety and hook execution
- `agent-runner`: Claude Code process management with streaming JSON, multi-turn, and timeout enforcement
- `prompt-builder`: Twig template rendering with issue and attempt context variables
- `orchestrator`: Polling loop, state machine, dispatch, concurrency control, retry, and reconciliation
- `logging`: Structured key=value log formatting with context propagation
- `cli`: Laravel Zero run command entry point with signal handling and graceful shutdown

### Modified Capabilities

(none -- greenfield project)

## Impact

- **New package**: `datashaman/symphony` (Laravel Zero CLI)
- **Dependencies**: `twig/twig`, `guzzlehttp/guzzle`, `pestphp/pest`
- **PHP extensions required**: `pcntl`, `posix`
- **External systems**: GitHub REST API, Jira REST API, Claude Code CLI
- **Runtime**: Long-running daemon process with forked child workers
