## 1. Foundation Files

- [x] 1.1 Rewrite README.md with project description, prerequisites (PHP 8.4+, ext-pcntl, ext-posix), installation, quick-start usage, and links to docs/
- [x] 1.2 Create CLAUDE.md with architecture summary, project structure, coding conventions, test commands, and development workflow
- [x] 1.3 Create .env.example with all environment variables (GITHUB_TOKEN, JIRA_API_TOKEN, JIRA_BASE_URL, JIRA_USER_EMAIL) and descriptive comments

## 2. Documentation Structure

- [x] 2.1 Create docs/ directory with tutorial/, how-to/, reference/, explanation/ subdirectories

## 3. Tutorial

- [x] 3.1 Write docs/tutorial/getting-started.md: end-to-end guide for running Symphony against a GitHub repository

## 4. How-to Guides

- [x] 4.1 Write docs/how-to/configure-github-tracker.md: GitHub API setup, active/terminal states, repository configuration
- [x] 4.2 Write docs/how-to/configure-jira-tracker.md: Jira API setup, JQL queries, state mapping, endpoint configuration
- [x] 4.3 Write docs/how-to/write-workflow-templates.md: YAML frontmatter structure, Twig template syntax, available issue variables, conditional blocks
- [x] 4.4 Write docs/how-to/tune-retry-and-timeouts.md: max_retries, turn_timeout_ms, stall_timeout_ms, polling interval, backoff behavior
- [x] 4.5 Write docs/how-to/configure-workspace-hooks.md: after_create, before_run, before_remove hooks with examples

## 5. Reference Documentation

- [x] 5.1 Write docs/reference/configuration.md: complete YAML configuration schema with types, defaults, and descriptions for all keys
- [x] 5.2 Write docs/reference/cli.md: CLI arguments for `./application run`, exit codes, signal handling
- [x] 5.3 Write docs/reference/environment-variables.md: all environment variables with types, defaults, and which tracker/component uses them
- [x] 5.4 Write docs/reference/issue-dto.md: all Issue object fields (identifier, title, description, state, priority, labels, url, raw) with types

## 6. Explanation Documentation

- [x] 6.1 Write docs/explanation/architecture.md: component overview, responsibilities, and interactions
- [x] 6.2 Write docs/explanation/orchestration-loop.md: tick phases (reconciliation, config reload, candidate fetch, sort/filter, dispatch)
- [x] 6.3 Write docs/explanation/multi-turn-sessions.md: first turn vs continuation, max_turns, session lifecycle
- [x] 6.4 Write docs/explanation/process-model.md: pcntl_fork, parent/child roles, signal handling (SIGINT/SIGTERM), workspace cleanup
