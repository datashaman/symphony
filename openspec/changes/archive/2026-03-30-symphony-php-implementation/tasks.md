## 1. Project Scaffolding

- [x] 1.1 Create Laravel Zero project via `composer create-project laravel-zero/laravel-zero symphony`
- [x] 1.2 Configure `composer.json` with `datashaman/symphony` namespace and PHP 8.4+ requirement
- [x] 1.3 Add dependencies: `twig/twig`, `guzzlehttp/guzzle`
- [x] 1.4 Set up PestPHP for testing (`pestphp/pest`, `pestphp/pest-plugin-laravel`)
- [x] 1.5 Create directory structure: `app/{Config,Workflow,Tracker,Workspace,Agent,Prompt,Orchestrator,Logging}`

## 2. Workflow Loader

- [x] 2.1 Implement `WorkflowLoader` class: split WORKFLOW.md at `---` markers, parse YAML front matter via Symfony YAML, return `['config' => array, 'prompt' => string]`
- [x] 2.2 Add validation for missing delimiters, empty prompt, and invalid YAML
- [x] 2.3 Write unit tests: valid file, missing front matter, empty prompt, invalid YAML, re-read on reload

## 3. Config Layer

- [x] 3.1 Implement `WorkflowConfig` class with typed getters and all spec defaults
- [x] 3.2 Implement `$VAR` and `${VAR}` environment variable resolution via `getenv()`
- [x] 3.3 Add validation: supported tracker.kind, api_key present and resolved, codex.command present
- [x] 3.4 Write unit tests: defaults applied, overrides, env resolution, unset var error, validation failures

## 4. Issue Model

- [x] 4.1 Implement `Issue` DTO with all fields: id, identifier, title, description, priority, state, branchName, url, labels, blockedBy, createdAt, updatedAt
- [x] 4.2 Add label lowercase normalization and UTC timestamp normalization
- [x] 4.3 Write unit tests for normalization behavior

## 5. Tracker Interface & GitHub Implementation

- [x] 5.1 Define `TrackerInterface` with `fetchCandidateIssues()`, `fetchIssuesByStates()`, `fetchStatesByIds()`
- [x] 5.2 Implement `GitHubTracker`: REST API calls via Guzzle, label-based state mapping, Link header pagination
- [x] 5.3 Implement GitHub identifier format (`{repo}#{number}`), branchName sanitization, blocked-by body parsing, priority label extraction
- [x] 5.4 Write unit tests with mocked HTTP responses: fetch candidates, pagination, state fetch, blocked-by detection

## 6. Jira Tracker Implementation

- [x] 6.1 Implement `JiraTracker`: REST API v3, Basic auth, JQL-based queries, startAt/maxResults pagination
- [x] 6.2 Implement Jira field mapping: status.name → state, priority.id → priority, issuelinks → blockedBy
- [x] 6.3 Write unit tests with mocked HTTP responses: JQL construction, pagination, normalization, blocked-by from links

## 7. Prompt Builder

- [x] 7.1 Implement `PromptBuilder` with Twig environment and StrictVariables enabled
- [x] 7.2 Add DateTime-to-ISO-8601 conversion for issue array values before rendering
- [x] 7.3 Expose `issue` (array) and `attempt` (int|null) as template variables
- [x] 7.4 Write unit tests: successful render, undefined variable error, DateTime conversion, attempt null vs integer

## 8. Workspace Manager

- [x] 8.1 Implement `WorkspaceManager::pathForIssue()` with identifier sanitization and realpath traversal check
- [x] 8.2 Implement `create()` (mkdir + after_create hooks) and `remove()` (before_remove hooks + rm -rf)
- [x] 8.3 Implement `runHook()` with proc_open, cwd, timeout enforcement, and fatal vs non-fatal handling
- [x] 8.4 Implement `cleanupTerminal()` for startup workspace cleanup
- [x] 8.5 Write unit tests: path computation, traversal prevention, hook execution, hook timeout, fatal vs non-fatal hooks, cleanup

## 9. Claude Code Agent Runner

- [x] 9.1 Implement `ClaudeCodeRunner`: proc_open launch, stdin write, stdin close, non-blocking stdout read
- [x] 9.2 Implement streaming JSON line parsing: extract session_id, accumulate token counts, track last activity
- [x] 9.3 Implement multi-turn support: first turn with full prompt, continuation turns with `--continue`, max_turns enforcement
- [x] 9.4 Implement timeout enforcement: turn_timeout_ms and stall_timeout_ms via hrtime() checks, SIGTERM on timeout
- [x] 9.5 Write unit tests with mocked subprocess: normal run, JSON parsing, timeout, stall, multi-turn, max turns

## 10. Orchestrator

- [x] 10.1 Implement in-memory state: running, claimed, retryQueue, codexTotals arrays
- [x] 10.2 Implement poll tick sequence: reconcile → reload config → fetch candidates → sort → filter → dispatch → sleep
- [x] 10.3 Implement dispatch via pcntl_fork: child creates workspace, builds prompt, runs agent, exits; parent tracks PID
- [x] 10.4 Implement retry logic: 1000ms continuation delay, exponential backoff for failures with cap
- [x] 10.5 Implement reconciliation: stall detection, tracker state refresh, terminal cleanup, non-active kill
- [x] 10.6 Implement graceful shutdown: SIGINT/SIGTERM handlers, shutdown flag, wait for children, exit 0
- [x] 10.7 Write unit tests: dispatch logic, concurrency limits, priority ordering, blocker filtering, retry backoff, stall detection

## 11. Structured Logging

- [x] 11.1 Implement `StructuredFormatter` as Monolog formatter outputting `key=value` pairs
- [x] 11.2 Add automatic context propagation for issue_id, issue_identifier, session_id
- [x] 11.3 Add secret redaction for api_key and other sensitive config values
- [x] 11.4 Write unit tests: format output, context fields, missing optional fields, redaction

## 12. CLI Command

- [x] 12.1 Implement `RunCommand` with signature `run {workflow=./WORKFLOW.md}`
- [x] 12.2 Wire up: load workflow → build config → create tracker → create orchestrator → run loop
- [x] 12.3 Register pcntl_signal handlers for SIGINT/SIGTERM before loop entry
- [x] 12.4 Implement exit codes: 0 on graceful shutdown, non-zero on startup failure
- [x] 12.5 Write feature test: missing file error, successful startup sequence

## 13. Example Workflow & Integration

- [x] 13.1 Create example `WORKFLOW.md` with GitHub tracker config and Twig prompt template
- [x] 13.2 Create example `WORKFLOW.jira.md` with Jira tracker config
- [x] 13.3 Manual smoke test: verify daemon starts, loads config, and enters poll loop
