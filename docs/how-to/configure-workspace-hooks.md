# Configure Workspace Setup

Workspace setup commands run shell commands when a new git worktree is created for an issue. Use them to install dependencies, copy configuration files, or prepare the environment.

## Configuration

Setup commands are an array of shell commands under `workspace.setup`:

```yaml
workspace:
  setup:
    - "cp %BASE%/.env .env"
    - "composer install --no-interaction --no-progress"
```

Each command runs in the worktree directory as the working directory.

## The `%BASE%` Placeholder

Use `%BASE%` in setup commands to reference the main repository root. This is useful for copying files from the base repo into the worktree:

```yaml
workspace:
  setup:
    - "cp %BASE%/.env .env"
    - "ln -sf %BASE%/vendor vendor"
```

## Setup Timeout

Configure how long each setup command is allowed to run:

```yaml
workspace:
  setup_timeout_ms: 60000  # 1 minute (default)
```

If a command exceeds this timeout, it's killed with SIGTERM and the workspace creation fails (fatal — the child process exits with an error).

## Examples

### PHP/Laravel Project

```yaml
workspace:
  setup:
    - "cp %BASE%/.env .env"
    - "composer install --no-interaction --no-progress"
    - "php artisan key:generate"
    - "php artisan migrate --force"
```

### Node.js Project

```yaml
workspace:
  setup:
    - "cp %BASE%/.env .env"
    - "npm install"
```

### Symlink Shared Dependencies

To avoid reinstalling dependencies in every worktree:

```yaml
workspace:
  setup:
    - "ln -sf %BASE%/vendor vendor"
    - "ln -sf %BASE%/node_modules node_modules"
    - "cp %BASE%/.env .env"
```

## Behavior Details

- Commands run sequentially in the order listed
- Each command runs in a separate process via `proc_open`
- The working directory is the worktree path
- stdout and stderr are captured; stderr is included in error messages on failure
- Setup commands only run when a new worktree is created — existing worktrees are reused without re-running setup
- If any setup command exits with a non-zero code, workspace creation fails and the child process exits with an error
