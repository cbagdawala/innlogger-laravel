<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Import;

use DateTimeImmutable;

/**
 * One entry read back from a Laravel log file by LaravelLogParser.
 */
final class LogEntry
{
    /**
     * @param  array<mixed>  $context
     * @param  array{class: string, message: string, file: ?string, line: ?int, trace: ?string}|null  $exception
     */
    public function __construct(
        public readonly DateTimeImmutable $occurredAt,
        public readonly string $environment,
        public readonly string $levelName,
        public readonly string $message,
        public readonly array $context,
        public readonly ?array $exception,
        /** sha1 of the raw entry: identical entries share it */
        public readonly string $fingerprint,
        /** 1-based line number of the entry's header in its file */
        public readonly int $lineNumber,
    ) {
    }
}
