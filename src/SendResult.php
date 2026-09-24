<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger;

/**
 * Outcome of a send. Never contains the payload, headers or secrets.
 */
final class SendResult
{
    public const SENT = 'sent';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $status,
        public readonly ?string $reason = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $eventId = null,
        public readonly ?string $logId = null,
        public readonly int $attempts = 0,
        public readonly ?string $error = null,
    ) {
    }

    public static function sent(int $httpStatus, ?string $eventId, ?string $logId, int $attempts): self
    {
        return new self(self::SENT, $httpStatus === 200 ? 'duplicate' : null, $httpStatus, $eventId, $logId, $attempts);
    }

    public static function skipped(string $reason, ?string $eventId = null): self
    {
        return new self(self::SKIPPED, $reason, null, $eventId);
    }

    public static function failed(string $reason, ?int $httpStatus = null, ?string $eventId = null, int $attempts = 0, ?string $error = null): self
    {
        return new self(self::FAILED, $reason, $httpStatus, $eventId, null, $attempts, $error);
    }

    public function successful(): bool
    {
        return $this->status === self::SENT;
    }

    public function wasSkipped(): bool
    {
        return $this->status === self::SKIPPED;
    }

    /** True when the portal answered 200 (event_id already stored). */
    public function duplicate(): bool
    {
        return $this->status === self::SENT && $this->httpStatus === 200;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'reason' => $this->reason,
            'http_status' => $this->httpStatus,
            'event_id' => $this->eventId,
            'log_id' => $this->logId,
            'attempts' => $this->attempts,
            'error' => $this->error,
        ];
    }
}
