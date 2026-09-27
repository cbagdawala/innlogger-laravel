<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Laravel\Logging;

use Cbagdawala\InnLogger\Client;
use Cbagdawala\InnLogger\Severity;
use DateTimeInterface;
use Throwable;

/**
 * Shared by the Monolog 3 and Monolog 2 handlers: maps a record to an InnLogger event.
 * It never throws: a failed delivery must not break the logging call.
 *
 * A string "category" context key becomes the event category and a Throwable
 * "exception" key becomes the normalized exception.
 *
 * @internal
 */
trait ForwardsRecords
{
    /**
     * Monolog's numeric level (100 debug ... 600 emergency) to an InnLogger severity.
     */
    public static function severityForValue(int $monologLevel): int
    {
        return match (true) {
            $monologLevel >= 500 => Severity::CRITICAL, // critical, alert, emergency
            $monologLevel >= 400 => Severity::ERROR,
            $monologLevel >= 300 => Severity::WARNING,
            $monologLevel >= 250 => Severity::NOTICE,
            $monologLevel >= 200 => Severity::INFO,
            default => Severity::DEBUG,
        };
    }

    /**
     * @param  array<mixed>  $context
     * @param  array<mixed>  $extra
     */
    private function forward(
        Client $client,
        int $monologLevel,
        string $levelName,
        string $message,
        array $context,
        string $channel,
        DateTimeInterface $datetime,
        array $extra,
    ): void {
        try {
            $severity = self::severityForValue($monologLevel);
            if (! $client->shouldSend($severity)) {
                return;
            }

            $attributes = [
                'occurred_at' => $datetime,
                'metadata' => ['channel' => $channel, 'monolog_level' => $levelName],
            ];

            if ($extra !== []) {
                $attributes['metadata']['extra'] = $extra;
            }

            $client->log($severity, $message, $context, $attributes);
        } catch (Throwable) {
            // Swallow: InnLogger must never break the host application's logging.
        }
    }
}
