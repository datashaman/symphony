# Configure Workspace Hooks

Workspace hooks run shell commands at specific points in a workspace's lifecycle. Use them to set up repositories, install dependencies, or clean up resources.

## Hook Phases

| Phase | When | Fatal on failure |
|-------|------|-----------------|
| `after_create` | After the workspace directory is created | Yes |
| `before_run` | Before the agent is launched (not yet implemented in dispatch) | Yes |
| `before_remove` | Before the workspace directory is deleted | No |

Fatal hooks cause the child process to exit with an error if they return a non-zero exit code. Non-fatal hooks log a warning and continue.

## Configuration

Hooks are arrays of shell commands under `workspace.hooks`:

```yaml
workspace:
  root: /tmp/symphony_workspaces
  hooks:
    after_create:
      - "git clone https://github.com/owner/repo.git ."
      - "composer install --no-interaction"
    before_run:
      - "git pull origin main"
    before_remove:
      - "git stash"
```

Each command is executed in the workspace directory as the working directory.

## Hook Timeout

All hooks share a single timeout setting:

```yaml
hooks:
  timeout_ms: 60000  # 1 minute (default)
```

If a hook exceeds this timeout, it's killed with SIGTERM.

## Examples

### Clone and Install Dependencies

```yaml
workspace:
  hooks:
    after_create:
      - "git clone https://github.com/owner/repo.git ."
      - "npm install"
    before_run:
      - "git checkout main"
      - "git pull origin main"
```

### Clean Up Before Removal

```yaml
workspace:
  hooks:
    before_remove:
      - "git push origin --delete $(git branch --show-current) 2>/dev/null || true"
```

### Multiple Setup Steps

```yaml
workspace:
  hooks:
    after_create:
      - "git clone https://github.com/owner/repo.git ."
      - "cp .env.example .env"
      - "composer install --no-interaction --no-progress"
      - "php artisan key:generate"
      - "php artisan migrate --force"
```

## Behavior Details

- Commands run sequentially in the order listed
- Each command runs in a separate process via `proc_open`
- The working directory is the workspace path (e.g., `/tmp/symphony_workspaces/issue-42`)
- stdout and stderr are captured; stderr is included in error messages on failure
- `after_create` hooks run once when the workspace is first created for an issue
- `before_remove` hooks run before cleanup on terminal state transitions and during startup cleanup of stale workspaces
