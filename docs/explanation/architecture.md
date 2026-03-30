# Architecture

Symphony is a long-running PHP daemon built on Laravel Zero. It follows a poll-dispatch-reconcile pattern where a single parent process coordinates multiple child processes, each running a Claude Code agent against a single issue.

## Component Overview

```
┌─────────────────────────────────────────────┐
│                 RunCommand                   │
│  CLI entry point, wires components, signals  │
└──────────────────────┬──────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────┐
│               Orchestrator                   │
│  Main loop: tick → reconcile → dispatch      │
│  Manages running workers, retry queue        │
└──┬──────┬──────┬──────┬──────┬──────────────┘
   │      │      │      │      │
   ▼      ▼      ▼      ▼      ▼
Tracker  Workspace  Prompt  Agent  Workflow
         Manager   Builder  Runner  Loader
```

### RunCommand

Entry point (`./application run`). Creates and wires all components, registers SIGINT/SIGTERM handlers, and starts the orchestrator loop.

### Orchestrator

The central coordinator. Runs an infinite loop where each tick:
1. Reconciles running workers (checks for exits, stalls, state changes)
2. Reloads the workflow config (enabling live config changes)
3. Fetches candidate issues from the tracker
4. Sorts and filters eligible issues
5. Dispatches new workers up to the concurrency limit
6. Processes the retry queue

### TrackerInterface / GitHubTracker / JiraTracker

Abstraction for issue fetching. Each tracker implementation queries its API, paginates results, and maps responses to the normalized `Issue` DTO. Trackers also support fetching states by ID for blocker checks and reconciliation.

### WorkspaceManager

Manages isolated directories for each issue. Handles creation, hook execution, and cleanup. Includes path traversal protection to prevent workspaces from escaping the root directory.

### PromptBuilder

Renders Twig templates with issue data and retry context. Converts DateTimes to ISO 8601 strings. Uses strict variable mode (undefined variables cause errors, not silent failures).

### ClaudeCodeRunner

Launches Claude Code via `proc_open`, manages streaming JSON output parsing, enforces turn and stall timeouts, and supports multi-turn sessions (first turn sends prompt, subsequent turns use `--continue`).

### WorkflowLoader

Parses workflow files: splits YAML frontmatter from the Twig prompt template, validates structure. Re-reads the file on each call, enabling live config changes.

## Data Flow

1. **WorkflowLoader** reads the workflow file and returns `{config, prompt}`
2. **WorkflowConfig** merges defaults, resolves env vars, validates
3. **Tracker** fetches issues and returns `Issue[]`
4. **Orchestrator** sorts, filters, and dispatches eligible issues
5. For each dispatched issue:
   - **WorkspaceManager** creates directory and runs `after_create` hooks
   - **PromptBuilder** renders the template with issue data
   - **ClaudeCodeRunner** pipes the prompt to Claude Code and monitors output
6. Parent process monitors children and handles retries/cleanup

## Key Design Decisions

**Process isolation via fork**: Each issue runs in a separate child process. This provides natural isolation - a crash in one agent doesn't affect others. The parent never does heavy work, it only coordinates.

**Polling over webhooks**: Symphony pulls from the tracker on a timer rather than receiving push events. This simplifies deployment (no public endpoint needed) and makes the system resilient to network interruptions.

**Live config reload**: The workflow file is re-read on every tick. This allows changing concurrency limits, timeouts, or even the prompt template without restarting the daemon.

**Normalized Issue DTO**: Both GitHub and Jira map to the same `Issue` structure. The orchestrator and prompt builder never deal with tracker-specific data.
