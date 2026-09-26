# Changelog

All notable changes to `cbagdawala/innlogger` are listed here. Versions follow [semantic versioning](https://semver.org).

## 1.0.0 (2026-09-26)

First release.

- `InnLogger` facade with `critical`, `error`, `warning`, `notice`, `info`, `debug`, `trace` and `exception`.
- `innlogger` log channel and driver; optional automatic exception reporting through Laravel's exception handler.
- Request context capture (route, method, redacted URL, request ID, user ID) and the optional `CaptureRequestContext` middleware.
- Heartbeat, plus the `innlogger:test`, `innlogger:status` and `innlogger:heartbeat` Artisan commands.
- HMAC-SHA256 signed requests, threshold filtering, bounded retries that reuse the event ID, 429 cooldown.
- Fail-silent transport with short timeouts and a recursion guard; the secret is kept out of dumps and serialization.
- Recursive, case-insensitive key redaction and value masking of messages and stack traces.
