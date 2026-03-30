# Issue DTO Reference

The `Issue` class (`App\Tracker\Issue`) is the normalized data transfer object used across all trackers. Both GitHub and Jira trackers map their API responses into this structure.

## Fields

| Field | Type | Description |
|-------|------|-------------|
| `id` | `string` | Internal issue ID from the tracker (GitHub issue number as string, Jira issue ID) |
| `identifier` | `string` | Human-readable identifier (e.g., `my-repo#42` for GitHub, `PROJ-123` for Jira) |
| `title` | `string` | Issue title / summary |
| `description` | `string` | Issue body / description text |
| `priority` | `?int` | Numeric priority (lower = higher priority). Null if no priority set. |
| `state` | `string` | Current state (e.g., `todo`, `In Progress`) |
| `branchName` | `string` | Git branch name (e.g., `symphony/my-repo__42`) |
| `url` | `string` | Web URL to view the issue in the browser |
| `labels` | `string[]` | Array of labels, normalized to lowercase |
| `blockedBy` | `string[]` | Array of issue IDs/keys that block this issue |
| `createdAt` | `DateTimeImmutable` | Creation timestamp, normalized to UTC |
| `updatedAt` | `DateTimeImmutable` | Last update timestamp, normalized to UTC |

All properties are `readonly`.

## Template Access

In workflow templates, the Issue is available as `issue` with all fields accessible. DateTimes are converted to ISO 8601 strings before rendering:

```twig
{{ issue.identifier }}: {{ issue.title }}
Created: {{ issue.createdAt }}
Priority: {{ issue.priority }}
Labels: {{ issue.labels | join(', ') }}
```

## Serialization

The `toArray()` method returns all fields as an associative array. This is the array passed to the Twig prompt builder.

## Tracker-Specific Mapping

### GitHub

| Issue Field | GitHub API Source |
|-------------|-------------------|
| `id` | Issue number (as string) |
| `identifier` | `{repo}#{number}` |
| `title` | `title` |
| `description` | `body` |
| `priority` | Extracted from `priority:<N>` or `priority <N>` labels |
| `state` | First matching label from terminal states, then active states |
| `branchName` | `symphony/{sanitized identifier}` (non-alphanumeric → `_`) |
| `url` | `html_url` |
| `labels` | Label names, lowercased |
| `blockedBy` | Extracted from `blocked by #N` patterns in issue body |
| `createdAt` | `created_at` |
| `updatedAt` | `updated_at` |

### Jira

| Issue Field | Jira API Source |
|-------------|-----------------|
| `id` | `id` |
| `identifier` | `key` (e.g., `PROJ-123`) |
| `title` | `fields.summary` |
| `description` | `fields.description` (ADF rendered to plain text) |
| `priority` | `fields.priority.id` as integer |
| `state` | `fields.status.name` |
| `branchName` | `symphony/{sanitized key}` (non-alphanumeric → `_`) |
| `url` | `self` (API URL) |
| `labels` | `fields.labels`, lowercased |
| `blockedBy` | From `fields.issuelinks` where type is "blocks" with `inwardIssue.key` |
| `createdAt` | `fields.created` |
| `updatedAt` | `fields.updated` |
