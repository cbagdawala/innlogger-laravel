<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Laravel\Logging;

use Cbagdawala\InnLogger\Client;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog 3 handler (Laravel 10+) that normalizes log records into InnLogger events.
 * Laravel 8 and 9 (Monolog 2) use InnLoggerMonolog2Handler; CreateInnLoggerLogger picks.
 */
final class InnLoggerHandler extends AbstractProcessingHandler
{
    use ForwardsRecords;

    public function __construct(
        private readonly Client $client,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    public static function severityFor(Level $level): int
    {
        return self::severityForValue($level->value);
    }

    protected function write(LogRecord $record): void
    {
        $this->forward(
            $this->client,
            $record->level->value,
            $record->level->getName(),
            $record->message,
            $record->context,
            $record->channel,
            $record->datetime,
            $record->extra,
        );
    }
}
