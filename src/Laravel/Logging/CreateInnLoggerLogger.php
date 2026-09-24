<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Laravel\Logging;

use Cbagdawala\InnLogger\Client;
use Illuminate\Container\Container;
use Monolog\Logger;

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
        $handler = new InnLoggerHandler(
            $client,
            $config['level'] ?? 'debug',
            (bool) ($config['bubble'] ?? true),
        );

        return new Logger((string) ($config['name'] ?? 'innlogger'), [$handler]);
    }
}
