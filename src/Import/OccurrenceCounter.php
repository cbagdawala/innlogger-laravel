<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Import;

/**
 * Numbers repeats of identical entries within one file: 0 for the first, 1 for the next...
 *
 * An entry's fingerprint includes its timestamp, so repeats can only occur within the same
 * second. Log files are (nearly) chronological, so fingerprints older than WINDOW seconds
 * are forgotten: memory stays flat on multi-GB files, while repeats that are not adjacent
 * (another request logged in between) still get distinct numbers. The same file always
 * yields the same numbers, which keeps re-imports idempotent.
 */
final class OccurrenceCounter
{
    private const WINDOW = 60;

    /** @var array<string, array{0: int, 1: int}> fingerprint => [count, unix second] */
    private array $seen = [];

    private int $prunedAt = 0;

    public function next(LogEntry $entry): int
    {
        $second = $entry->occurredAt->getTimestamp();

        if ($second - $this->prunedAt > self::WINDOW) {
            foreach ($this->seen as $fingerprint => [, $at]) {
                if ($second - $at > self::WINDOW) {
                    unset($this->seen[$fingerprint]);
                }
            }
            $this->prunedAt = $second;
        }

        $occurrence = isset($this->seen[$entry->fingerprint]) ? $this->seen[$entry->fingerprint][0] + 1 : 0;
        $this->seen[$entry->fingerprint] = [$occurrence, $second];

        return $occurrence;
    }
}
