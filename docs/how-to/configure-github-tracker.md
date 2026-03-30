# Configure the GitHub Tracker

## Basic Setup

Set `tracker.kind` to `github` in your workflow file:

```yaml
tracker:
  kind: github
  repository: owner/repo
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

Set `tracker.repository` to the `owner/repo` format:

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

Issues without any matching label are ignored (neither active nor terminal).

## How Candidate Issues Are Fetched

The GitHub tracker:
1. Lists open issues from the repository via the GitHub REST API
2. Paginates through all results (100 per page)
3. Maps each issue's labels to determine its state
4. Returns issues whose state matches one of the `active_states`

## Blocked Issues

If an issue body or metadata references other issues (via the `blockedBy` field on the Issue DTO), Symphony will check the state of those blocking issues. If any blocker is in an `active_state`, the issue is skipped until the blocker is resolved.

## Priority

GitHub issues don't have a native priority field. The tracker extracts priority from labels matching the pattern `priority:<N>` (e.g., `priority:1`). Lower numbers are higher priority. Issues without a priority label default to the lowest priority.
