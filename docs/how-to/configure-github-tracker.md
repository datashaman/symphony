# Configure the GitHub Tracker

## Basic Setup

Set `tracker.kind` to `github` in your workflow file:

```yaml
tracker:
  kind: github
  api_key: $GITHUB_TOKEN
  active_states:
    - todo
    - in-progress
  terminal_states:
    - done
    - closed
    - cancelled
```

## Authentication

Create a GitHub personal access token with `repo` scope (classic) or fine-grained token with Issues read/write permission. Store it in your `.env`:

```
GITHUB_TOKEN=ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Reference it in the workflow as `$GITHUB_TOKEN` or `${GITHUB_TOKEN}`.

## Repository

By default, Symphony auto-detects the repository from the CWD's `origin` git remote. To override, set `tracker.repository` explicitly:

```yaml
tracker:
  repository: datashaman/my-project
```

## Issue States

Symphony uses label-based state mapping for GitHub issues. Configure which labels map to active (workable) and terminal (finished) states:

```yaml
tracker:
  active_states:
    - todo
    - in-progress
    - bug
  terminal_states:
    - done
    - closed
    - cancelled
    - wontfix
```

State matching is case-insensitive. An issue with the label "Todo" matches `todo`.

State determination checks terminal states first, then active states. Issues without any matching label get the state `unknown` and are ignored.

## Label Auto-Creation

At startup, Symphony calls `ensureLabels()` which creates any missing `active_states` and `terminal_states` labels on the GitHub repository. This ensures the configured labels exist before polling begins.

## How Candidate Issues Are Fetched

The GitHub tracker:
1. Lists open issues from the repository via the GitHub REST API
2. Paginates through all results (100 per page)
3. Skips pull requests (GitHub's issues endpoint returns both)
4. Maps each issue's labels to determine its state
5. Returns issues whose state matches one of the `active_states`

## Issue Identifiers

GitHub issues use the format `{repo}#{number}` as their identifier (e.g., `my-project#42`). Branch names are derived as `symphony/{repo}__{number}` with non-alphanumeric characters replaced by underscores.

## Blocked Issues

The tracker parses issue body text for `blocked by #N` patterns (case-insensitive). If any blocking issue is still in an active state, Symphony skips the blocked issue until the blocker resolves.

## Priority

GitHub issues don't have a native priority field. The tracker extracts priority from labels matching the pattern `priority:<N>` or `priority <N>` (e.g., `priority:1`). Lower numbers are higher priority. Issues without a priority label default to null (sorted last).
