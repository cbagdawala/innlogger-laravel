# Changelog

All notable changes to `cbagdawala/innlogger` are listed here. Versions follow [semantic versioning](https://semver.org).

## 1.2.2 (2026-09-28)

- Fixes heartbeats silently stopping for up to 24 hours when `INNLOGGER_HEARTBEAT=true`. The built-in schedule ran `innlogger:heartbeat` with `runInBackground()` and `withoutOverlapping()`; a background run releases its overlap lock only through a follow-up `schedule:finish`, so when the host killed that child process the lock stayed for Laravel's default 1440 minutes (`schedule:list` showed "Has Mutex") and the project appeared offline. The heartbeat now runs in the foreground (it is a single short HTTP call) with `withoutOverlapping(10)`, so a stale lock expires after 10 minutes at most.
- Upgrading: an install already stuck with a stale lock recovers by itself within 24 hours; to recover at once, run `php artisan schedule:clear-cache` once after `composer update cbagdawala/innlogger`.

## 1.2.1 (2026-09-28)

- Supports Guzzle 8 as well as Guzzle 7 (`guzzlehttp/guzzle` `^7.5|^8.0`). Laravel 13 apps with Guzzle 8 locked can now install the SDK; Composer keeps Guzzle 7 where the app requires it. Tested with Guzzle 8 on Laravel 8, 10 and 13, and Guzzle 7 on Laravel 8, 12 and 13.
- `timeout` and `connect_timeout` below 1 ms are raised to 1 ms: Guzzle 8 rejects smaller positive values, which would have made every send fail.

## 1.2.0 (2026-09-27)

- `innlogger:import`: backfills existing Laravel log files (`storage/logs/laravel*.log` by default) at their original times. Imported events are flagged so they never send alerts; event IDs are derived from each entry, so re-running never duplicates. Options: `--since`, `--until`, `--level`, `--environment`, `--rate`, `--alerts`, `--dry-run`.
- `Client::pausedFor()`: seconds until sending resumes after an HTTP 429.

## 1.1.0 (2026-09-27)

- Supports Laravel 8 and 9 (Monolog 2) as well as Laravel 10 to 13. Tested on each version from 8 to 13.
- `Http::fake()` in the host app's tests now intercepts InnLogger requests on Laravel 8 and 9 too.

## 1.0.1 (2026-09-27)

- Released under the MIT licence and published on Packagist: install with a plain `composer require`, no repository entry or token needed.

## 1.0.0 (2026-09-26)

First release.

- `InnLogger` facade with `critical`, `error`, `warning`, `notice`, `info`, `debug`, `trace` and `exception`.
- `innlogger` log channel and driver; optional automatic exception reporting through Laravel's exception handler.
- Request context capture (route, method, redacted URL, request ID, user ID) and the optional `CaptureRequestContext` middleware.
- Heartbeat, plus the `innlogger:test`, `innlogger:status` and `innlogger:heartbeat` Artisan commands.
- HMAC-SHA256 signed requests, threshold filtering, bounded retries that reuse the event ID, 429 cooldown.
- Fail-silent transport with short timeouts and a recursion guard; the secret is kept out of dumps and serialization.
- Recursive, case-insensitive key redaction and value masking of messages and stack traces.
