## Why

Symphony manually wires Monolog, uses raw Guzzle for HTTP, and requires users to `source .env` before running. Laravel Zero provides built-in components for all of these — dotenv, logging, HTTP client — plus PHAR builds and self-update. Adopting them reduces custom code and gives users a better experience.

## What Changes

- Install dotenv component for automatic `.env` loading
- Install logging component with `config/logging.php` using StructuredFormatter on stderr
- Install HTTP client component, replace raw Guzzle in trackers with Laravel's HTTP facade
- Enable PHAR build via `app:build`
- Install self-update component for auto-updating from GitHub releases
- Set app name to Symphony

## Capabilities

### New Capabilities

_(none)_

### Modified Capabilities

- `laravel-zero`: Adopt dotenv, logging, HTTP client, PHAR build, and self-update components

## Impact

- **Code**: `RunCommand.php` (use Log facade), `GitHubTracker.php` and `JiraTracker.php` (use Http facade)
- **Config**: New `config/logging.php`
- **Tests**: Tracker tests rewritten to use `Http::fake()` instead of Guzzle MockHandler
- **Dependencies**: Added `illuminate/log`, `illuminate/http`, `vlucas/phpdotenv`, `laravel-zero/phar-updater`
