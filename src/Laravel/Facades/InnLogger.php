<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Laravel\Facades;

use Cbagdawala\InnLogger\Client;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Cbagdawala\InnLogger\SendResult critical(string|\Stringable $message, array $context = [], array $attributes = [])
 * @method static \Cbagdawala\InnLogger\SendResult error(string|\Stringable $message, array $context = [], array $attributes = [])
 * @method static \Cbagdawala\InnLogger\SendResult warning(string|\Stringable $message, array $context = [], array $attributes = [])
 * @method static \Cbagdawala\InnLogger\SendResult notice(string|\Stringable $message, array $context = [], array $attributes = [])
 * @method static \Cbagdawala\InnLogger\SendResult info(string|\Stringable $message, array $context = [], array $attributes = [])
 * @method static \Cbagdawala\InnLogger\SendResult debug(string|\Stringable $message, array $context = [], array $attributes = [])
 * @method static \Cbagdawala\InnLogger\SendResult trace(string|\Stringable $message, array $context = [], array $attributes = [])
 * @method static \Cbagdawala\InnLogger\SendResult exception(\Throwable $exception, array $context = [], array $attributes = [], int|string|null $level = null)
 * @method static \Cbagdawala\InnLogger\SendResult log(int|string $level, string|\Stringable $message, array $context = [], array $attributes = [])
 * @method static \Cbagdawala\InnLogger\SendResult heartbeat(array $extra = [])
 * @method static \Cbagdawala\InnLogger\SendResult send(array $payload)
 * @method static bool shouldSend(int $level)
 * @method static \Cbagdawala\InnLogger\Config config()
 *
 * @see \Cbagdawala\InnLogger\Client
 */
final class InnLogger extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
