<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Laravel\Logging;

use Cbagdawala\InnLogger\Client;
use Cbagdawala\InnLogger\Severity;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Monolog handler that normalizes Laravel log records into InnLogger events.
 * It never throws: a failed delivery must not break the logging call.
 *
 * A string "category" context key becomes the event category and a Throwable
 * "exception" key becomes the normalized exception.
 */
final class InnLoggerHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly Client $client,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    public static function severityFor(Level $level): int
    {
        return match ($level) {
            Level::Emergency, Level::Alert, Level::Critical => Severity::CRITICAL,
            Level::Error => Severity::ERROR,
            Level::Warning => Severity::WARNING,
            Level::Notice => Severity::NOTICE,
            Level::Info => Severity::INFO,
            Level::Debug => Severity::DEBUG,
        };
    }

    protected function write(LogRecord $record): void
    {
        try {
            $severity = self::severityFor($record->level);
            if (! $this->client->shouldSend($severity)) {
                return;
            }

            $attributes = [
                'occurred_at' => $record->datetime,
                'metadata' => ['channel' => $record->channel, 'monolog_level' => $record->level->getName()],
            ];

            if ($record->extra !== []) {
                $attributes['metadata']['extra'] = $record->extra;
            }

            $this->client->log($severity, $record->message, $record->context, $attributes);
        } catch (Throwable) {
            // Swallow: InnLogger must never break the host application's logging.
        }
    }
}
