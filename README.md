# InnLogger Laravel SDK (`cbagdawala/innlogger`)

Ships application logs and exceptions from a Laravel app to an
InnLogger portal over signed HTTPS requests. It is
built to never break the host application: short timeouts, every failure
swallowed, no recursion.

- Laravel 8, 9, 10, 11, 12 and 13, PHP 8.1+, Guzzle 7 or 8
- `InnLogger` facade: `critical`, `error`, `warning`, `notice`, `info`, `debug`, `trace`, `exception`
- `Log::channel('innlogger')` log channel (and stack support)
- Optional automatic exception reporting, request context capture and heartbeat
- `innlogger:test`, `innlogger:status`, `innlogger:heartbeat` and `innlogger:import` Artisan commands
- Recursive, case-insensitive redaction plus value masking

## Installation

```bash
composer require cbagdawala/innlogger
php artisan vendor:publish --tag=innlogger-config   # optional: config/innlogger.php
```

The service provider and the `InnLogger` alias are auto-discovered.

## Configuration

Create a project and credential in the InnLogger portal, then add to `.env`:

```env
INNLOGGER_URL=https://logger.example.com
INNLOGGER_API_KEY=ilv_xxxxxxxxx
INNLOGGER_API_SECRET=ils_xxxxxxxxx
INNLOGGER_LOG_LEVEL=2
INNLOGGER_TIMEOUT=2
INNLOGGER_ENABLED=true
INNLOGGER_ENVIRONMENT=production
INNLOGGER_AUTO_EXCEPTION=true
```

| Env | Config key | Default | Meaning |
|---|---|---|---|
| `INNLOGGER_ENABLED` | `enabled` | `true` | Master switch. `false` = no network calls at all. |
| `INNLOGGER_URL` | `url` | – | Portal base URL (the SDK appends `/api/v1/...`). |
| `INNLOGGER_API_KEY` | `api_key` | – | Project API key (`ilv_...`). |
| `INNLOGGER_API_SECRET` | `api_secret` | – | Signing secret (`ils_...`). Never sent over the wire. |
| `INNLOGGER_LOG_LEVEL` | `log_level` | `2` | Transmission threshold, 0–7 (see below). |
| `INNLOGGER_TIMEOUT` | `timeout` | `2` | Total request timeout, seconds. |
| `INNLOGGER_CONNECT_TIMEOUT` | `connect_timeout` | `1` | Connect timeout, seconds. |
| `INNLOGGER_ENVIRONMENT` | `environment` | `APP_ENV` | Environment name sent with each event. |
| `INNLOGGER_APPLICATION` | `application` | `APP_NAME` | Application name. |
| `INNLOGGER_APP_VERSION` | `application_version` | – | Sent in heartbeats and event metadata. |
| `INNLOGGER_HOSTNAME` | `hostname` | `gethostname()` | Hostname override. |
| `INNLOGGER_CATEGORY` | `category` | `application` | Default category (exceptions use `exception`). |
| `INNLOGGER_AUTO_EXCEPTION` | `auto_exception` | `false` | Report exceptions from Laravel's exception handler. |
| `INNLOGGER_EXCEPTION_LEVEL` | `exception_level` | `2` | Severity for exceptions. |
| `INNLOGGER_FAIL_SILENT` | `fail_silent` | `true` | See *Failure behaviour*. |
| `INNLOGGER_ALLOW_INSECURE` | `allow_insecure` | `false` | Permit `http://` URLs (local development only). |
| `INNLOGGER_RETRIES` | `retries` | `1` | Extra attempts for transient failures (max 5). |
| `INNLOGGER_RETRY_DELAY_MS` | `retry_delay_ms` | `100` | Delay before retry *n* is `n × delay`. |
| `INNLOGGER_TRANSPORT` | `transport` | `laravel` | `laravel` (HTTP client, works with `Http::fake()`) or `guzzle`. |
| `INNLOGGER_DIAGNOSTICS_CHANNEL` | `diagnostics_channel` | – | Local log channel for delivery failures (e.g. `single`). |
| `INNLOGGER_CAPTURE_REQUEST` | `capture.request` | `true` | Capture route/method/URL/request ID/status. |
| `INNLOGGER_CAPTURE_USER` | `capture.user` | `true` | Capture the authenticated user ID. |
| `INNLOGGER_HEARTBEAT` | `heartbeat.schedule` | `false` | Schedule `innlogger:heartbeat` every five minutes (needs the `schedule:run` cron; see *Heartbeat*). |
| – | `redact_fields` | see file | Extra keys to redact. |
| – | `mask_patterns` | `[]` | Extra `regex => replacement` masking rules. |
| – | `limits.*` | 16 KB / 64 KB | Message, trace, context and metadata size limits. |

## Usage

```php
use Cbagdawala\InnLogger\Laravel\Facades\InnLogger;

InnLogger::critical('System failure', $context);
InnLogger::error('Payment failed', ['category' => 'payment', 'order_id' => $order->id]);
InnLogger::warning('Slow API response', ['ms' => 3200]);
InnLogger::notice('Important event');
InnLogger::info('Customer created', ['customer_id' => 12]);
InnLogger::debug('Calculated values', $values);
InnLogger::trace('Detailed diagnostic');
InnLogger::exception($exception, ['order_id' => 5]);          // ERROR by default
InnLogger::exception($exception, [], [], 1);                 // as CRITICAL
```

- `$context` is free-form data (redacted, max 64 KB). A string `category` key becomes the
  event category; a `Throwable` under `exception` becomes the normalized exception.
- An optional third argument sets top-level event fields: `category`, `request_id`,
  `user_id`, `url`, `http_method`, `http_status`, `file`, `line`, `metadata`,
  `occurred_at`, `event_id`.
- Every call returns a `SendResult` (`successful()`, `status`, `reason`, `httpStatus`,
  `eventId`, `logId`, `duplicate()`), which you can ignore.

### Log channel

The package registers an `innlogger` log driver and, unless you define one yourself, an
`innlogger` channel:

```php
Log::channel('innlogger')->error('Payment failed', ['category' => 'payment']);
```

Add it to your stack to mirror normal logging (the threshold still applies):

```php
// config/logging.php
'stack' => ['driver' => 'stack', 'channels' => ['daily', 'innlogger']],
'innlogger' => ['driver' => 'innlogger', 'level' => 'debug'],
// or: ['driver' => 'custom', 'via' => \Cbagdawala\InnLogger\Laravel\Logging\CreateInnLoggerLogger::class]
```

Monolog levels map to: emergency/alert/critical → 1, error → 2, warning → 3,
notice → 4, info → 5, debug → 6. The channel name and Monolog level go into `metadata`.

### Automatic exception reporting

With `INNLOGGER_AUTO_EXCEPTION=true`, the SDK adds a `reportable()` callback to Laravel's
exception handler. It runs alongside Laravel's normal reporting and never replaces it,
respects `dontReport`, and never throws. If the `innlogger` channel is also in your log
stack, the same exception object is sent only once.

### Request context

For HTTP requests the SDK adds the route (name or URI), HTTP method, URL (path and query,
with sensitive parameters redacted), request ID, authenticated user ID (only when a guard
has already resolved the user, so no extra queries), hostname and application version.
It never captures request bodies, Authorization headers, cookies or passwords.

To also record the response status code and a stable request ID (taken from
`X-Request-Id` or generated), add the middleware:

```php
// bootstrap/app.php (Laravel 11+)
->withMiddleware(fn (Middleware $m) => $m->append(\Cbagdawala\InnLogger\Laravel\Http\CaptureRequestContext::class))
```

### Heartbeat

`php artisan innlogger:heartbeat` posts `{environment, hostname, application_version}` to
`/api/v1/heartbeat`. Schedule it yourself or set `INNLOGGER_HEARTBEAT=true`, which registers
it as `everyFiveMinutes()->withoutOverlapping(10)`. It runs in the foreground (not
`runInBackground()`), since it is one short HTTP call, and it is not `onOneServer()`: each server
reports its own hostname. Heartbeats honour `enabled` but not the log threshold.

Laravel's scheduler only runs if the server's cron calls it every minute:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

**Troubleshooting: the project shows offline.** Run `php artisan schedule:list`. If the
`innlogger:heartbeat` entry shows "Has Mutex", a stale overlap lock is blocking it (SDK
versions before 1.2.2 ran the heartbeat in the background, and a killed background run left the
lock for 24 hours). Upgrade, then clear the lock once:

```bash
php artisan schedule:clear-cache
```

If nothing is listed, check that `INNLOGGER_HEARTBEAT=true` is set (and the config cache
refreshed with `php artisan config:cache`) and that the cron entry above exists.

### Artisan commands

```bash
php artisan innlogger:test     # sends a test event (bypasses the threshold) and reports config
                               # validity, reachability, authentication, status and event ID
php artisan innlogger:status   # shows the configuration (secret never printed) and sends a heartbeat
php artisan innlogger:status --offline
```

### Importing existing log files

`innlogger:import` backfills what is already in `storage/logs` (for example, the history from
before you installed the SDK):

```bash
php artisan innlogger:import --dry-run                 # count what would be sent, send nothing
php artisan innlogger:import                           # storage/logs/laravel*.log
php artisan innlogger:import storage/logs/laravel-2026-09-*.log --since=2026-09-01 --level=warning
```

- Entries keep their **original time**. Times without an offset are read in `app.timezone`.
- Imported events **never trigger email alerts**, and they don't count as recent activity
  in the portal. Pass `--alerts` if you do want your notification rules to run on them.
- Re-running is safe: each entry gets an event ID derived from its content, and the portal
  skips IDs it already stored. If an import stops (network, rate limit), run the same command again.
- `--level` defaults to `INNLOGGER_LOG_LEVEL` (2 = ERROR and CRITICAL); `--until` stops before
  a time; `--environment` overrides the environment written in each entry.
- `--rate` caps events per second (default 50; the portal allows 10,000 per minute per project,
  shared with live traffic). A rate-limited import waits and carries on.
- Stack traces, the exception class, file and line are rebuilt from the log text. Context is
  redacted like live events. Anything older than the project's retention period is removed by
  the portal's next nightly cleanup.

Requires an InnLogger portal that supports imported events (2026-09-27 or later). An older
portal stores the events but sends alerts for them, so do a `--dry-run` and check with the
portal's administrator first.

## Threshold semantics

Severity: `0 OFF, 1 CRITICAL, 2 ERROR, 3 WARNING, 4 NOTICE, 5 INFO, 6 DEBUG, 7 TRACE`.
An event is sent when `level <= INNLOGGER_LOG_LEVEL`; `0` sends nothing.

| `INNLOGGER_LOG_LEVEL` | Sent |
|---|---|
| 0 | nothing |
| 2 | CRITICAL, ERROR |
| 5 | CRITICAL … INFO |
| 7 | everything |

The threshold only controls what is transmitted. Alerting is configured with notification
rules in the portal.

## Wire protocol

`POST {url}/api/v1/logs` and `POST {url}/api/v1/heartbeat`, JSON body, headers
`X-InnLogger-Key`, `X-InnLogger-Timestamp` (unix seconds), `X-InnLogger-Nonce` (random,
per attempt), `X-InnLogger-Signature`, `X-InnLogger-Request-Id`, where

```
signature = hex(HMAC-SHA256(timestamp + "\n" + nonce + "\n" + raw_body, api_secret))
```

Each event gets a UUID v4 `event_id` before sending. Retries resend the same body, and so the
same `event_id` (the portal de-duplicates on it), with a fresh timestamp, nonce and signature.

## Failure behaviour

- A short timeout (2 s, connect 1 s) and at most `retries` extra attempts, only for
  connection errors/timeouts and HTTP 502/503/504. 401/403/413/422 are not retried.
  The worst case per event is roughly `(retries + 1) × timeout`.
- HTTP 429 pauses sending for `retry_after` seconds (in this PHP process); events in that
  window are dropped rather than queued.
- HTTP 200 (duplicate `event_id`) counts as success.
- Every error (transport, serialization, context capture) is caught. With
  `fail_silent=true` (the default) calls return a failed `SendResult` and nothing is thrown.
  With `fail_silent=false`, direct `InnLogger::*` calls throw `InnLoggerException`
  (useful while developing); the log channel and the automatic exception hook still
  never throw.
- An InnLogger failure is never logged back into InnLogger: a re-entrancy guard drops any
  event produced while a send is in progress. Local diagnostics go only to
  `diagnostics_channel` (off by default) and never include payloads, headers or secrets.
- Missing configuration or a plain `http://` URL disables sending; no request is made.
- Sending is synchronous (no queue); keep the timeout short.

## Security notes

- Keep `INNLOGGER_API_SECRET` in `.env`/your secret store only. It signs requests and is
  never transmitted; if it appears inside a logged string, it is masked.
- HTTPS is required. `INNLOGGER_ALLOW_INSECURE=true` is for local development only.
  Redirects are not followed.
- Redaction is recursive and case-insensitive (`-` and `_` are treated alike). Always
  redacted: `password`, `password_confirmation`, `current_password`, `new_password`,
  `token`, `access_token`, `refresh_token`, `id_token`, `authorization`,
  `proxy_authorization`, `cookie`, `set_cookie`, `card_number`, `cvv`, `cvc`, `secret`,
  `api_secret`, `client_secret`, `api_key`, `private_key`, `csrf_token`, `xsrf_token`,
  `x_xsrf_token`, `_token`, plus your `redact_fields`. The same keys are redacted in URL
  query strings.
- Masking rules replace `Bearer`/`Basic`/`Digest` credentials and `ils_...` secrets in any
  string (message, exception message, trace, context, metadata); add your own with
  `mask_patterns`.
- Treat logs as sensitive data: avoid logging personal or payment details in the first
  place. Redaction is a safety net, not a licence.
- Limits: message 16 KB, trace 64 KB (truncated), context and metadata 64 KB each
  (replaced by a `_truncated` marker if larger), so the body stays below the portal's
  256 KB limit.

## Framework-agnostic core

`Client`, `Config`, `Signer`, `PayloadBuilder`, `Redactor`, `Severity` and
`Transport\GuzzleTransport` have no Laravel dependency:

```php
$client = new \Cbagdawala\InnLogger\Client(
    new \Cbagdawala\InnLogger\Config(['url' => ..., 'api_key' => ..., 'api_secret' => ..., 'log_level' => 2]),
    new \Cbagdawala\InnLogger\Transport\GuzzleTransport(),
);
$client->error('Payment failed', ['order_id' => 1]);
```

## Testing the package

> This repository is a read-only mirror, published automatically from the private InnLogger repository. Pull requests here would be overwritten; please open an issue instead.

```bash
composer install
./vendor/bin/phpunit
```
