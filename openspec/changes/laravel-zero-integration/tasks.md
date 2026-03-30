## 1. Dotenv

- [x] 1.1 Install dotenv component via `app:install dotenv`
- [x] 1.2 Verify `getenv()` returns values from `.env` at app boot time

## 2. Logging

- [x] 2.1 Install logging component via `app:install log`
- [x] 2.2 Configure `config/logging.php` with stderr channel using StructuredFormatter
- [x] 2.3 Replace manual Monolog wiring in RunCommand with `Log` facade

## 3. HTTP Client

- [x] 3.1 Install HTTP client component via `app:install http`
- [x] 3.2 Rewrite GitHubTracker to use `Http` facade instead of raw Guzzle
- [x] 3.3 Rewrite JiraTracker to use `Http` facade instead of raw Guzzle
- [x] 3.4 Rewrite GitHubTrackerTest to use `Http::fake()` with URL patterns
- [x] 3.5 Rewrite JiraTrackerTest to use `Http::fake()` with URL patterns and sequences
- [x] 3.6 Bind tracker tests to TestCase in Pest.php for facade access

## 4. PHAR Build

- [x] 4.1 Build standalone PHAR via `app:build symphony`
- [x] 4.2 Add `/builds` to `.gitignore`
- [x] 4.3 Set app name to Symphony in `config/app.php`

## 5. Self-Update

- [x] 5.1 Install self-update component via `app:install self-update`
