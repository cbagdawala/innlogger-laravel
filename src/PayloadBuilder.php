<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger;

use Cbagdawala\InnLogger\Contracts\ContextProvider;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Stringable;
use Throwable;

/**
 * Normalizes an event into the InnLogger v1 Event Contract (spec 03 §2).
 */
final class PayloadBuilder
{
    public const TRUNCATED_SUFFIX = "\n...[truncated]";

    private const MAX_PREVIOUS = 5;

    /** Frames from these paths are skipped when detecting the caller file/line. */
    private const INTERNAL_PATHS = [
        '/vendor/laravel/framework/',
        '/vendor/illuminate/',
        '/vendor/monolog/',
        '/vendor/psr/log/',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Redactor $redactor,
        private readonly ?ContextProvider $contextProvider = null,
    ) {
    }

    /**
     * @param  array<mixed>  $context  Free-form context. A string "category" key
     *                                 and a Throwable "exception" key are lifted
     *                                 to the top-level fields.
     * @param  array<string, mixed>  $attributes  Top-level overrides: event_id, category,
     *                                            request_id, user_id, url, http_method,
     *                                            http_status, file, line, exception,
     *                                            metadata, occurred_at, hostname.
     * @return array<string, mixed>
     */
    public function build(int $level, string|Stringable $message, array $context = [], array $attributes = []): array
    {
        $ambient = $this->ambient();

        if (isset($context['category']) && is_string($context['category']) && ! isset($attributes['category'])) {
            $attributes['category'] = $context['category'];
            unset($context['category']);
        }

        if (($context['exception'] ?? null) instanceof Throwable && ! isset($attributes['exception'])) {
            $attributes['exception'] = $context['exception'];
            unset($context['exception']);
        }

        $metadata = array_merge(
            is_array($ambient['metadata'] ?? null) ? $ambient['metadata'] : [],
            is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [],
        );

        $exception = null;
        $exceptionFile = null;
        $exceptionLine = null;
        $source = $attributes['exception'] ?? null;
        if ($source instanceof Throwable) {
            $exception = $this->normalizeException($source);
            $exceptionFile = $exception['file'];
            $exceptionLine = $exception['line'];
            $previous = $this->previousChain($source);
            if ($previous !== []) {
                $metadata['previous_exceptions'] = $previous;
            }
        } elseif (is_array($source)) {
            $exception = $this->redactor->redact($source);
        }

        $file = $attributes['file'] ?? $exceptionFile;
        $line = $attributes['line'] ?? $exceptionLine;
        if ($file === null && $line === null) {
            [$file, $line] = $this->caller();
        }

        $url = $attributes['url'] ?? $ambient['url'] ?? null;

        $payload = [
            'event_id' => $this->eventId($attributes['event_id'] ?? null),
            'level' => $level,
            'level_name' => Severity::name($level),
            'message' => self::truncate($this->redactor->maskString((string) $message), $this->config->maxMessageBytes),
            'category' => self::limit((string) ($attributes['category'] ?? ($source instanceof Throwable ? 'exception' : $this->config->category)), 100),
            'environment' => self::limit($this->config->environment, 100),
            'application' => self::limit($this->config->application, 191),
            'hostname' => self::limit($attributes['hostname'] ?? $ambient['hostname'] ?? $this->hostname(), 255),
            'request_id' => self::limit($attributes['request_id'] ?? $ambient['request_id'] ?? null, 191),
            'user_id' => $this->userId($attributes['user_id'] ?? $ambient['user_id'] ?? null),
            'url' => is_string($url) ? self::limit($this->redactor->redactUrl($url), 2048) : null,
            'http_method' => self::limit(isset($attributes['http_method']) || isset($ambient['http_method'])
                ? strtoupper((string) ($attributes['http_method'] ?? $ambient['http_method']))
                : null, 16),
            'http_status' => self::intOrNull($attributes['http_status'] ?? $ambient['http_status'] ?? null),
            'file' => self::limit(is_string($file) ? $file : null, 1024),
            'line' => self::intOrNull($line),
            'exception' => $exception,
            'context' => $this->section($this->redactor->redact($context), $this->config->maxContextBytes),
            'metadata' => $this->section($this->redactor->redact($metadata), $this->config->maxMetadataBytes),
            'occurred_at' => $this->occurredAt($attributes['occurred_at'] ?? null),
        ];

        return array_filter($payload, static fn ($value): bool => $value !== null);
    }

    /**
     * @return array{class: string, message: string, file: string, line: int, trace: string}
     */
    public function normalizeException(Throwable $e): array
    {
        return [
            'class' => $e::class,
            'message' => self::truncate($this->redactor->maskString($e->getMessage()), $this->config->maxMessageBytes),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => self::truncate($this->redactor->maskString($e->getTraceAsString()), $this->config->maxTraceBytes),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function heartbeat(array $extra = []): array
    {
        $ambient = $this->ambient();

        return array_filter([
            'environment' => $this->config->environment,
            'hostname' => $ambient['hostname'] ?? $this->hostname(),
            'application_version' => $this->config->applicationVersion,
        ] + $this->redactor->redact($extra), static fn ($value): bool => $value !== null);
    }

    /**
     * Encode a payload exactly as it will be sent (and signed).
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws \JsonException
     */
    public static function encode(array $payload): string
    {
        foreach (['context', 'metadata'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] === []) {
                $payload[$key] = new \stdClass();
            }
        }

        return json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Truncate to at most $maxBytes bytes without splitting a UTF-8 character.
     */
    public static function truncate(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $keep = max(0, $maxBytes - strlen(self::TRUNCATED_SUFFIX));
        $cut = function_exists('mb_strcut') ? mb_strcut($value, 0, $keep, 'UTF-8') : substr($value, 0, $keep);

        return $cut.self::TRUNCATED_SUFFIX;
    }

    /**
     * @return array<string, mixed>
     */
    private function ambient(): array
    {
        if ($this->contextProvider === null) {
            return [];
        }

        try {
            return $this->contextProvider->context();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function section(array $data, int $maxBytes): array
    {
        try {
            $bytes = strlen(self::encode(['x' => $data]));
        } catch (Throwable) {
            return ['_truncated' => true, '_reason' => 'not_encodable'];
        }

        if ($bytes <= $maxBytes) {
            return $data;
        }

        return [
            '_truncated' => true,
            '_original_bytes' => $bytes,
            '_keys' => array_slice(array_map('strval', array_keys($data)), 0, 50),
        ];
    }

    /**
     * @return list<array{class: string, message: string, file: string, line: int}>
     */
    private function previousChain(Throwable $e): array
    {
        $chain = [];
        $previous = $e->getPrevious();
        while ($previous !== null && count($chain) < self::MAX_PREVIOUS) {
            $chain[] = [
                'class' => $previous::class,
                'message' => self::truncate($this->redactor->maskString($previous->getMessage()), 2048),
                'file' => $previous->getFile(),
                'line' => $previous->getLine(),
            ];
            $previous = $previous->getPrevious();
        }

        return $chain;
    }

    /**
     * First stack frame outside this SDK and the logging framework.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function caller(): array
    {
        $sdkPath = str_replace('\\', '/', __DIR__);

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            if (! isset($frame['file'])) {
                continue;
            }
            $file = str_replace('\\', '/', $frame['file']);
            if (str_starts_with($file, $sdkPath)) {
                continue;
            }
            foreach (self::INTERNAL_PATHS as $internal) {
                if (str_contains($file, $internal)) {
                    continue 2;
                }
            }

            return [$frame['file'], $frame['line'] ?? null];
        }

        return [null, null];
    }

    private function eventId(mixed $eventId): string
    {
        return is_string($eventId) && Uuid::isV4(strtolower($eventId)) ? strtolower($eventId) : Uuid::v4();
    }

    private function userId(mixed $userId): int|string|null
    {
        if (is_int($userId)) {
            return $userId;
        }

        if (is_string($userId) && $userId !== '') {
            return self::limit($userId, 191);
        }

        return null;
    }

    private function occurredAt(mixed $value): string
    {
        $utc = new DateTimeZone('UTC');

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        }

        if (is_string($value) && $value !== '') {
            try {
                return (new DateTimeImmutable($value))->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
            } catch (Throwable) {
                // fall through to "now"
            }
        }

        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private function hostname(): ?string
    {
        if ($this->config->hostname !== null) {
            return $this->config->hostname;
        }

        $hostname = gethostname();

        return is_string($hostname) && $hostname !== '' ? $hostname : null;
    }

    private static function limit(mixed $value, int $max): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $value = (string) $value;
        if ($value === '') {
            return null;
        }

        return strlen($value) > $max
            ? (function_exists('mb_strcut') ? mb_strcut($value, 0, $max, 'UTF-8') : substr($value, 0, $max))
            : $value;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
