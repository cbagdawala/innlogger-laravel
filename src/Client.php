<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger;

use Cbagdawala\InnLogger\Contracts\ContextProvider;
use Cbagdawala\InnLogger\Transport\TransportException;
use Cbagdawala\InnLogger\Transport\TransportInterface;
use Cbagdawala\InnLogger\Transport\TransportResponse;
use Closure;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;
use WeakMap;

/**
 * Framework-agnostic InnLogger client.
 *
 * Every public method is safe to call from anywhere: it applies the threshold,
 * never throws into the host application (unless fail_silent is false) and
 * never re-enters itself (an InnLogger failure is never logged back to InnLogger).
 */
class Client
{
    /** Re-entrancy guard shared by every client in the process. */
    private static int $depth = 0;

    private readonly Signer $signer;
    private readonly Redactor $redactor;
    private readonly PayloadBuilder $builder;
    private readonly Closure $sleep;
    private readonly Closure $clock;

    /** Unix time until which sending is paused after an HTTP 429. */
    private int $pausedUntil = 0;

    /** @var WeakMap<Throwable, true> exceptions already reported by this client */
    private WeakMap $reported;

    /** @var array<string, true> diagnostics already written once */
    private array $diagnosed = [];

    public function __construct(
        private readonly Config $config,
        private readonly TransportInterface $transport,
        ?ContextProvider $contextProvider = null,
        private readonly ?LoggerInterface $diagnostics = null,
        ?Closure $sleep = null,
        ?Closure $clock = null,
    ) {
        $this->signer = new Signer($config->apiKey, $config->apiSecret());
        $this->redactor = new Redactor($config->redactFields, $config->maskPatterns, [$config->apiSecret()]);
        $this->builder = new PayloadBuilder($config, $this->redactor, $contextProvider);
        $this->sleep = $sleep ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
        $this->clock = $clock ?? static fn (): int => time();
        $this->reported = new WeakMap();
    }

    /**
     * var_dump()/print_r()/debug bars never show the secret.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'config' => $this->config,
            'transport' => $this->transport::class,
            'pausedUntil' => $this->pausedUntil,
        ];
    }

    /**
     * A live client (transport, closures, signing key) is not serializable.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('InnLogger Client cannot be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('InnLogger Client cannot be unserialized.');
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function payloadBuilder(): PayloadBuilder
    {
        return $this->builder;
    }

    public function redactor(): Redactor
    {
        return $this->redactor;
    }

    /** Would an event of this severity be transmitted right now? */
    /**
     * Seconds until sending resumes after an HTTP 429 (0 when not paused).
     */
    public function pausedFor(): int
    {
        return max(0, $this->pausedUntil - ($this->clock)());
    }

    public function shouldSend(int $level): bool
    {
        return $this->config->enabled && Severity::shouldSend($level, $this->config->logLevel);
    }

    /** @param array<mixed> $context @param array<string, mixed> $attributes */
    public function critical(string|Stringable $message, array $context = [], array $attributes = []): SendResult
    {
        return $this->log(Severity::CRITICAL, $message, $context, $attributes);
    }

    /** @param array<mixed> $context @param array<string, mixed> $attributes */
    public function error(string|Stringable $message, array $context = [], array $attributes = []): SendResult
    {
        return $this->log(Severity::ERROR, $message, $context, $attributes);
    }

    /** @param array<mixed> $context @param array<string, mixed> $attributes */
    public function warning(string|Stringable $message, array $context = [], array $attributes = []): SendResult
    {
        return $this->log(Severity::WARNING, $message, $context, $attributes);
    }

    /** @param array<mixed> $context @param array<string, mixed> $attributes */
    public function notice(string|Stringable $message, array $context = [], array $attributes = []): SendResult
    {
        return $this->log(Severity::NOTICE, $message, $context, $attributes);
    }

    /** @param array<mixed> $context @param array<string, mixed> $attributes */
    public function info(string|Stringable $message, array $context = [], array $attributes = []): SendResult
    {
        return $this->log(Severity::INFO, $message, $context, $attributes);
    }

    /** @param array<mixed> $context @param array<string, mixed> $attributes */
    public function debug(string|Stringable $message, array $context = [], array $attributes = []): SendResult
    {
        return $this->log(Severity::DEBUG, $message, $context, $attributes);
    }

    /** @param array<mixed> $context @param array<string, mixed> $attributes */
    public function trace(string|Stringable $message, array $context = [], array $attributes = []): SendResult
    {
        return $this->log(Severity::TRACE, $message, $context, $attributes);
    }

    /**
     * Report an exception (default severity: exception_level, ERROR unless configured).
     * The same exception object is only reported once per process.
     *
     * @param  array<mixed>  $context
     * @param  array<string, mixed>  $attributes
     */
    public function exception(Throwable $exception, array $context = [], array $attributes = [], int|string|null $level = null): SendResult
    {
        $attributes['exception'] = $exception;

        if (! isset($attributes['http_status']) && method_exists($exception, 'getStatusCode')) {
            try {
                $status = $exception->getStatusCode();
                if (is_int($status)) {
                    $attributes['http_status'] = $status;
                }
            } catch (Throwable) {
                // ignore
            }
        }

        return $this->log($level ?? $this->config->exceptionLevel, $exception->getMessage(), $context, $attributes);
    }

    /**
     * Send one event if it passes the enabled flag and threshold.
     *
     * @param  array<mixed>  $context
     * @param  array<string, mixed>  $attributes  see PayloadBuilder::build()
     */
    public function log(int|string $level, string|Stringable $message, array $context = [], array $attributes = []): SendResult
    {
        if (self::$depth > 0) {
            return SendResult::skipped('recursion');
        }

        try {
            $severity = Severity::fromMixed($level);
            if ($severity === null) {
                return SendResult::skipped('invalid_level');
            }

            if (! $this->config->enabled) {
                return SendResult::skipped('disabled');
            }

            if (! Severity::shouldSend($severity, $this->config->logLevel)) {
                return SendResult::skipped('below_threshold');
            }

            $exception = $attributes['exception'] ?? $context['exception'] ?? null;
            if ($exception instanceof Throwable) {
                if (isset($this->reported[$exception])) {
                    return SendResult::skipped('duplicate_exception');
                }
                $this->reported[$exception] = true;
            }

            self::$depth++;
            try {
                $payload = $this->builder->build($severity, $message, $context, $attributes);
            } finally {
                self::$depth--;
            }

            return $this->send($payload);
        } catch (InnLoggerException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->fail(SendResult::failed('internal_error', null, null, 0, $e::class));
        }
    }

    /**
     * Send a heartbeat (independent of the log threshold, but honours `enabled`).
     *
     * @param  array<string, mixed>  $extra
     */
    public function heartbeat(array $extra = [], ?bool $failSilent = null): SendResult
    {
        if (self::$depth > 0) {
            return SendResult::skipped('recursion');
        }

        if (! $this->config->enabled) {
            return SendResult::skipped('disabled');
        }

        try {
            return $this->post('heartbeat', $this->builder->heartbeat($extra), null, $failSilent);
        } catch (InnLoggerException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->fail(SendResult::failed('internal_error', null, null, 0, $e::class), $failSilent);
        }
    }

    /**
     * Low-level: sign and POST an already-built event payload to /api/v1/logs.
     * Bypasses the enabled flag and threshold (used by innlogger:test), but still
     * enforces configuration and HTTPS rules and never throws when fail_silent.
     *
     * @param  array<string, mixed>  $payload
     * @param  bool|null  $failSilent  override the configured fail_silent for this call
     */
    public function send(array $payload, ?bool $failSilent = null): SendResult
    {
        if (! isset($payload['event_id']) || ! is_string($payload['event_id'])) {
            $payload = ['event_id' => Uuid::v4()] + $payload;
        }

        try {
            return $this->post('logs', $payload, $payload['event_id'], $failSilent);
        } catch (InnLoggerException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->fail(SendResult::failed('internal_error', null, $payload['event_id'], 0, $e::class), $failSilent);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $path, array $payload, ?string $eventId, ?bool $failSilent = null): SendResult
    {
        $problem = $this->config->problem();
        if ($problem !== null) {
            return $this->fail(SendResult::failed($problem, null, $eventId), $failSilent);
        }

        $now = ($this->clock)();
        if ($this->pausedUntil > $now) {
            return SendResult::skipped('rate_limited', $eventId);
        }

        // The body is encoded once: every retry sends (and signs) the exact same
        // bytes, so the event_id never changes between attempts.
        $body = PayloadBuilder::encode($payload);
        $url = $this->config->endpoint($path);
        $requestId = Uuid::v4();
        $maxAttempts = $this->config->retries + 1;
        $attempt = 0;
        $result = null;

        self::$depth++;
        try {
            while ($attempt < $maxAttempts) {
                $attempt++;
                $retryable = false;

                // Fresh timestamp and nonce for every attempt (nonces are single-use).
                $headers = $this->signer->headers($body, $requestId, ($this->clock)());

                try {
                    $response = $this->transport->post($url, $headers, $body, $this->config->timeout, $this->config->connectTimeout);
                    [$result, $retryable] = $this->interpret($response, $eventId, $attempt);
                } catch (TransportException $e) {
                    $result = SendResult::failed('transport_error', null, $eventId, $attempt, $this->describe($e));
                    $retryable = true;
                }

                if (! $retryable || $attempt >= $maxAttempts) {
                    break;
                }

                if ($this->config->retryDelayMs > 0) {
                    ($this->sleep)($this->config->retryDelayMs * $attempt);
                }
            }
        } finally {
            self::$depth--;
        }

        return $result->successful() ? $result : $this->fail($result, $failSilent);
    }

    /**
     * @return array{0: SendResult, 1: bool} result and whether it is safe to retry
     */
    private function interpret(TransportResponse $response, ?string $eventId, int $attempt): array
    {
        $status = $response->status;

        if ($response->successful()) {
            $data = $response->json()['data'] ?? [];
            $logId = is_array($data) && isset($data['log_id']) && is_scalar($data['log_id']) ? (string) $data['log_id'] : null;

            return [SendResult::sent($status, $eventId, $logId, $attempt), false];
        }

        if ($status === 429) {
            $retryAfter = $response->json()['retry_after'] ?? $response->header('retry-after');
            $seconds = is_numeric($retryAfter) ? (int) $retryAfter : 60;
            $this->pausedUntil = ($this->clock)() + max(1, min($seconds, 3600));

            return [SendResult::failed('rate_limited', $status, $eventId, $attempt), false];
        }

        $reason = match (true) {
            $status === 401 => 'invalid_credentials',
            $status === 403 => 'forbidden',
            $status === 413 => 'payload_too_large',
            $status === 422 => 'validation_failed',
            $status >= 500 => 'server_error',
            default => 'http_error',
        };

        return [SendResult::failed($reason, $status, $eventId, $attempt), in_array($status, [502, 503, 504], true)];
    }

    private function fail(SendResult $result, ?bool $failSilent = null): SendResult
    {
        $this->diagnose($result);

        if (! ($failSilent ?? $this->config->failSilent) && ! in_array($result->reason, ['rate_limited'], true)) {
            throw new InnLoggerException(sprintf(
                'InnLogger send failed: %s%s',
                (string) $result->reason,
                $result->httpStatus !== null ? ' (HTTP '.$result->httpStatus.')' : '',
            ));
        }

        return $result;
    }

    /**
     * Local diagnostic without payload, headers or secrets. Configuration
     * problems are only reported once per process to avoid log spam.
     */
    private function diagnose(SendResult $result): void
    {
        if ($this->diagnostics === null) {
            return;
        }

        if (in_array($result->reason, ['not_configured', 'invalid_url', 'insecure_url'], true)) {
            if (isset($this->diagnosed[(string) $result->reason])) {
                return;
            }
            $this->diagnosed[(string) $result->reason] = true;
        }

        self::$depth++;
        try {
            $this->diagnostics->warning('InnLogger: event not delivered', [
                'reason' => $result->reason,
                'http_status' => $result->httpStatus,
                'event_id' => $result->eventId,
                'attempts' => $result->attempts,
                'error' => $result->error,
            ]);
        } catch (Throwable) {
            // Diagnostics are best effort.
        } finally {
            self::$depth--;
        }
    }

    private function describe(TransportException $e): string
    {
        $previous = $e->getPrevious();

        return $previous !== null ? $previous::class : $e::class;
    }
}
