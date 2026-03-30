## Context

Symphony was manually wiring infrastructure that Laravel Zero provides as installable components. The framework's `app:install` commands add dotenv, logging, HTTP client, PHAR build, and self-update with minimal configuration.

## Goals / Non-Goals

**Goals:**
- Use `app:install dotenv` for automatic `.env` loading
- Use `app:install log` with StructuredFormatter on stderr as default channel
- Use `app:install http` and replace raw Guzzle with `Http` facade in trackers
- Use `app:build` for standalone PHAR distribution
- Use `app:install self-update` for auto-updating

**Non-Goals:**
- Changing application behavior or tracker logic
- Adding new features beyond framework integration

## Decisions

### 1. Full URLs instead of baseUrl()

Laravel's HTTP `PendingRequest::baseUrl()` always prepends the base URL, which breaks GitHub's Link header pagination (returns absolute URLs). Solution: pass full URLs to `get()` and store `$baseUrl` as a string property.

### 2. Tracker tests use Http::fake()

Replaced Guzzle's `MockHandler` with Laravel's `Http::fake()` for URL-pattern-based response stubs. Tests require `TestCase` binding in `Pest.php` for facade access.

### 3. Default log channel is stderr

Set `LOG_CHANNEL=stderr` as default in `config/logging.php` with `StructuredFormatter::class` as the formatter. Removed manual Monolog wiring from `RunCommand`.

## Risks / Trade-offs

- **[Test coupling]** Tracker tests now depend on Laravel's service container for Http facade. -> Mitigation: Bound via `uses(TestCase::class)` in Pest.php.
