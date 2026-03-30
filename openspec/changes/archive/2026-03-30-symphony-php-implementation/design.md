## Context

OpenAI's Symphony spec defines a daemon that polls an issue tracker, dispatches coding agents to isolated workspaces, and manages their lifecycle. The reference implementation is Elixir/Phoenix using Linear and Codex. We are building a PHP 8.4+ implementation using Laravel Zero, targeting GitHub Issues + Jira as trackers and Claude Code as the coding agent. The project is greenfield -- no existing code.

The SYMPHONY.md spec (~/SYMPHONY.md) provides our detailed implementation plan. The original OpenAI SPEC.md defines the canonical behavior we must match.

## Goals / Non-Goals

**Goals:**
- Faithful PHP implementation of the Symphony spec's core orchestration loop
- Support GitHub Issues (label-based state mapping) and Jira (native workflow states) as trackers
- Use Claude Code CLI as the agent runner with streaming JSON output and multi-turn support
- Process-based concurrency via `pcntl_fork` for parallel agent execution
- WORKFLOW.md-driven configuration with Twig prompt templates
- Structured logging, graceful shutdown, workspace isolation

**Non-Goals:**
- Dashboard / HTTP status surface (deferred to a later phase per SYMPHONY.md)
- SSH worker extension for remote execution
- Linear integration (replaced by GitHub + Jira)
- Codex JSON-RPC protocol (replaced by Claude Code CLI streaming)
- PHAR distribution (optional, not in initial scope)

## Decisions

### 1. Laravel Zero as framework

**Choice**: Laravel Zero CLI micro-framework
**Over**: Raw PHP, Symfony Console, custom CLI
**Rationale**: Provides service container, config management, Artisan command structure, and Monolog integration out of the box. Minimal overhead for a CLI daemon. The team already has Laravel expertise.

### 2. pcntl_fork for concurrency

**Choice**: `pcntl_fork()` to spawn child processes per issue
**Over**: ReactPHP event loop, Fibers, Amp, ext-parallel
**Rationale**: The spec requires true process isolation -- each agent runs in its own workspace with its own Claude Code subprocess. Fork matches the spec's model directly. Parent uses non-blocking `pcntl_waitpid(WNOHANG)` for status checks. Simple, well-understood, no additional dependencies.

### 3. Twig for prompt templating

**Choice**: Twig with `StrictVariables` extension
**Over**: Blade, Liquid, simple string replacement
**Rationale**: Twig's strict mode catches undefined variables at render time (matching the spec's error behavior). Rich filter/function support. Standalone library, no Laravel dependency. The original spec uses Liquid-style syntax; Twig's `{{ }}` is nearly identical.

### 4. Guzzle for HTTP

**Choice**: Guzzle HTTP client
**Over**: Laravel HTTP facade, cURL directly, Symfony HttpClient
**Rationale**: Industry standard for PHP HTTP. Supports pagination helpers, retry middleware, and streaming responses. Already widely used in the ecosystem.

### 5. Tracker abstraction via interface

**Choice**: `TrackerInterface` with `GitHubTracker` and `JiraTracker` implementations
**Over**: Single tracker class with conditionals
**Rationale**: Clean separation. Each tracker has fundamentally different APIs (REST + labels vs REST + JQL). Interface enforces the three required methods: `fetchCandidateIssues`, `fetchIssuesByStates`, `fetchStatesByIds`.

### 6. Claude Code CLI stdio protocol

**Choice**: Launch `claude -p --output-format stream-json` via `proc_open`, write prompt to stdin, read streaming JSON from stdout
**Over**: HTTP API, SDK integration
**Rationale**: Claude Code CLI is the specified agent. The `stream-json` output format provides structured events (token counts, session IDs, completion status). Multi-turn via `--continue` flag. Direct process control allows timeout enforcement via non-blocking reads and `hrtime()` monitoring.

### 7. In-memory state only

**Choice**: All orchestrator state (running, claimed, retry queue, token totals) kept in PHP arrays
**Over**: SQLite, Redis, file-based state
**Rationale**: Matches the spec exactly -- Symphony is a single-process daemon. State is reconstructed on restart via tracker reconciliation. No persistence layer needed.

### 8. Environment variable resolution

**Choice**: Custom `$VAR` / `${VAR}` resolution in config values using `getenv()`
**Over**: dotenv, Laravel config, putenv
**Rationale**: Spec requires `$ENV_VAR` syntax in WORKFLOW.md YAML. Simple regex replacement at config load time. No mutation of the environment.

## Risks / Trade-offs

**[pcntl_fork unavailable on some platforms]** → Mitigation: Document PHP `pcntl` extension as a hard requirement. Provide clear error message at startup if missing. This is a CLI daemon, not a web server -- pcntl is expected.

**[Claude Code CLI version drift]** → Mitigation: Parse the `stream-json` output format defensively. Log unknown event types rather than crashing. Document minimum Claude Code version.

**[GitHub API rate limits]** → Mitigation: Use conditional requests (If-None-Match/ETag) where possible. Default polling interval of 30s is conservative. Log rate limit headers.

**[Jira API pagination complexity]** → Mitigation: Implement proper startAt/maxResults pagination. Cap at reasonable limits per query.

**[Long-running process memory]** → Mitigation: Child processes are forked and exit after completing their issue. Parent process state is bounded by active issue count. Workspace cleanup prevents disk accumulation.

**[Workspace path traversal]** → Mitigation: Strict sanitization (`[A-Za-z0-9._-]` only) and `realpath()` validation that resolved path starts with workspace root. Defense in depth.
