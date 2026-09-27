<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Laravel\Logging;

use Cbagdawala\InnLogger\Client;
use Illuminate\Container\Container;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * Factory for a `custom` log channel:
 *
 *   'innlogger' => ['driver' => 'custom', 'via' => CreateInnLoggerLogger::class, 'level' => 'debug'],
 *
 * The package also registers an `innlogger` driver, so `'driver' => 'innlogger'` works too.
 */
final class CreateInnLoggerLogger
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        return self::make(Container::getInstance()->make(Client::class), $config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function make(Client $client, array $config): Logger
    {
        // Monolog 3 (Laravel 10+) passes LogRecord objects; Monolog 2 (Laravel 8/9) passes arrays.
        $handlerClass = class_exists(LogRecord::class) ? InnLoggerHandler::class : InnLoggerMonolog2Handler::class;

        $handler = new $handlerClass(
            $client,
            $config['level'] ?? 'debug',
            (bool) ($config['bubble'] ?? true),
        );

        return new Logger((string) ($config['name'] ?? 'innlogger'), [$handler]);
    }
}
