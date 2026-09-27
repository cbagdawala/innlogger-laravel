<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Laravel\Logging;

use Cbagdawala\InnLogger\Client;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

/**
 * Monolog 2 handler (Laravel 8 and 9), where records are arrays. Only loaded when
 * Monolog 2 is installed: its write() signature is incompatible with Monolog 3.
 */
final class InnLoggerMonolog2Handler extends AbstractProcessingHandler
{
    use ForwardsRecords;

    /**
     * @param  int|string  $level
     */
    public function __construct(
        private readonly Client $client,
        $level = Logger::DEBUG,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function write(array $record): void
    {
        $this->forward(
            $this->client,
            (int) $record['level'],
            (string) $record['level_name'],
            (string) $record['message'],
            (array) $record['context'],
            (string) $record['channel'],
            $record['datetime'],
            (array) ($record['extra'] ?? []),
        );
    }
}
