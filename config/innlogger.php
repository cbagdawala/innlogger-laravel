<?php

/*
|--------------------------------------------------------------------------
| InnLogger SDK configuration
|--------------------------------------------------------------------------
|
| Severity scale (lower = more severe):
|   0 OFF, 1 CRITICAL, 2 ERROR, 3 WARNING, 4 NOTICE, 5 INFO, 6 DEBUG, 7 TRACE
|
| An event is transmitted when `level <= log_level`. A log_level of 0 sends
| nothing. The threshold only controls what is transmitted; alerting is
| configured separately in the InnLogger portal.
|
*/

return [

    // Master switch. When false nothing is sent and no network call is made.
    'enabled' => env('INNLOGGER_ENABLED', true),

    // Portal base URL, e.g. https://logger.example.com (no trailing /api/v1).
    'url' => env('INNLOGGER_URL'),

    'api_key' => env('INNLOGGER_API_KEY'),

    // Never commit this value. It is only used to sign requests; it is never sent.
    'api_secret' => env('INNLOGGER_API_SECRET'),

    // Transmission threshold (0-7). 2 = CRITICAL + ERROR.
    'log_level' => (int) env('INNLOGGER_LOG_LEVEL', 2),

    // Total request timeout and connect timeout, in seconds. Keep them short.
    'timeout' => (float) env('INNLOGGER_TIMEOUT', 2),
    'connect_timeout' => (float) env('INNLOGGER_CONNECT_TIMEOUT', 1),

    'environment' => env('INNLOGGER_ENVIRONMENT', env('APP_ENV', 'production')),

    // Application name and version reported with every event / heartbeat.
    'application' => env('INNLOGGER_APPLICATION', env('APP_NAME')),
    'application_version' => env('INNLOGGER_APP_VERSION'),

    // Category used when an event does not set one (exceptions use "exception").
    'category' => env('INNLOGGER_CATEGORY', 'application'),

    // Overrides gethostname() when set (useful in containers).
    'hostname' => env('INNLOGGER_HOSTNAME'),

    // Report exceptions through Laravel's exception handler (in addition to,
    // never instead of, Laravel's normal reporting).
    'auto_exception' => env('INNLOGGER_AUTO_EXCEPTION', false),

    // Severity used for exceptions reported automatically or via InnLogger::exception().
    'exception_level' => (int) env('INNLOGGER_EXCEPTION_LEVEL', 2),

    // true: every InnLogger failure is swallowed. false: InnLogger::* calls made
    // directly by your code throw InnLoggerException (the log channel and the
    // automatic exception hook still never throw). Keep true in production.
    'fail_silent' => env('INNLOGGER_FAIL_SILENT', true),

    // Plain http:// URLs are refused unless this is true (local development only).
    'allow_insecure' => env('INNLOGGER_ALLOW_INSECURE', false),

    // Bounded retry for transient failures (connection error/timeout, 502, 503, 504).
    // Every retry reuses the same event_id so the portal de-duplicates it.
    'retries' => (int) env('INNLOGGER_RETRIES', 1),
    'retry_delay_ms' => (int) env('INNLOGGER_RETRY_DELAY_MS', 100),

    // 'laravel' uses Laravel's HTTP client (works with Http::fake()), 'guzzle' uses Guzzle directly.
    'transport' => env('INNLOGGER_TRANSPORT', 'laravel'),

    // Optional local log channel for SDK diagnostics (e.g. "single"). null = off.
    // Diagnostics never include the payload, headers or secrets. Do not point
    // this at the innlogger channel (it would be ignored anyway).
    'diagnostics_channel' => env('INNLOGGER_DIAGNOSTICS_CHANNEL'),

    // Keys whose values are replaced with "[REDACTED]" anywhere in context,
    // metadata and URL query strings. Matching is case-insensitive and treats
    // "-" and "_" alike. The SDK always adds its built-in list (password,
    // password_confirmation, token, access_token, refresh_token, authorization,
    // cookie, card_number, cvv, secret, api_secret, ...); list extra keys here.
    'redact_fields' => [
        'password',
        'password_confirmation',
        'token',
        'authorization',
        'cookie',
        'card_number',
        'cvv',
    ],

    // Regex => replacement rules applied to every string value (message,
    // exception message, context, metadata). Added to the built-in rules that
    // mask bearer/basic credentials and InnLogger secrets.
    'mask_patterns' => [
        // '/\b\d{3}-\d{2}-\d{4}\b/' => '[SSN]',
    ],

    // Automatic request context (route, method, URL, request ID, user ID,
    // hostname, application version). Passwords, Authorization headers,
    // cookies and request bodies are never captured.
    'capture' => [
        'request' => env('INNLOGGER_CAPTURE_REQUEST', true),
        'user' => env('INNLOGGER_CAPTURE_USER', true),
    ],

    // Size limits (bytes), matching the portal's defaults.
    'limits' => [
        'message' => 16384,
        'trace' => 65536,
        'context' => 65536,
        'metadata' => 65536,
    ],

    'heartbeat' => [
        // Register `innlogger:heartbeat` with the scheduler (every five minutes).
        'schedule' => env('INNLOGGER_HEARTBEAT', false),
    ],

];
