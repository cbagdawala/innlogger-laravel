<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Laravel;

use Cbagdawala\InnLogger\Client;
use Cbagdawala\InnLogger\Config;
use Cbagdawala\InnLogger\Laravel\Console\HeartbeatCommand;
use Cbagdawala\InnLogger\Laravel\Console\StatusCommand;
use Cbagdawala\InnLogger\Laravel\Console\TestCommand;
use Cbagdawala\InnLogger\Laravel\Logging\CreateInnLoggerLogger;
use Cbagdawala\InnLogger\Transport\GuzzleTransport;
use Cbagdawala\InnLogger\Transport\TransportInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Throwable;

final class InnLoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/innlogger.php', 'innlogger');

        $this->app->singleton(Config::class, static function (Application $app): Config {
            $settings = (array) $app['config']->get('innlogger', []);
            $settings['application'] ??= $app['config']->get('app.name');
            $settings['environment'] ??= $app['config']->get('app.env');

            return new Config($settings);
        });

        $this->app->singleton(TransportInterface::class, static function (Application $app): TransportInterface {
            if ($app['config']->get('innlogger.transport') === 'guzzle') {
                return new GuzzleTransport();
            }

            return new LaravelHttpTransport(static fn (): HttpFactory => $app->make(HttpFactory::class));
        });

        $this->app->singleton(Client::class, static function (Application $app): Client {
            $config = $app->make(Config::class);

            return new Client(
                $config,
                $app->make(TransportInterface::class),
                new LaravelContextProvider(
                    $app,
                    (bool) $app['config']->get('innlogger.capture.request', true),
                    (bool) $app['config']->get('innlogger.capture.user', true),
                    $config->hostname,
                    $config->applicationVersion,
                ),
                self::diagnosticsLogger($app),
            );
        });

        $this->app->alias(Client::class, 'innlogger');

        // `Log::channel('innlogger')` works without touching config/logging.php.
        $config = $this->app['config'];
        if ($config->get('logging.channels.innlogger') === null) {
            $config->set('logging.channels.innlogger', [
                'driver' => 'innlogger',
                'level' => 'debug',
            ]);
        }

        $this->callAfterResolving('log', static function (LogManager $log): void {
            $log->extend('innlogger', function ($app, array $config) {
                return CreateInnLoggerLogger::make($app->make(Client::class), $config);
            });
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/innlogger.php' => $this->app->configPath('innlogger.php'),
            ], ['innlogger', 'innlogger-config']);

            $this->commands([
                TestCommand::class,
                StatusCommand::class,
                HeartbeatCommand::class,
            ]);
        }

        if ($this->enabled('innlogger.auto_exception')) {
            $this->registerExceptionReporting();
        }

        if ($this->enabled('innlogger.heartbeat.schedule')) {
            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('innlogger:heartbeat')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
            });
        }
    }

    /**
     * Adds a reportable callback: it runs inside Laravel's normal reporting and
     * returns nothing, so Laravel still logs/handles the exception as usual.
     */
    private function registerExceptionReporting(): void
    {
        $app = $this->app;

        $this->callAfterResolving(ExceptionHandler::class, static function ($handler) use ($app): void {
            if (! method_exists($handler, 'reportable')) {
                return;
            }

            $handler->reportable(static function (Throwable $e) use ($app): void {
                try {
                    $app->make(Client::class)->exception($e);
                } catch (Throwable) {
                    // Never interfere with Laravel's handling of the original exception.
                }
            });
        });
    }

    private function enabled(string $key): bool
    {
        $value = $this->app['config']->get($key);

        return is_bool($value) ? $value : (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false);
    }

    private static function diagnosticsLogger(Application $app): ?LoggerInterface
    {
        $channel = $app['config']->get('innlogger.diagnostics_channel');
        if (! is_string($channel) || $channel === '' || $channel === 'innlogger') {
            return null;
        }

        try {
            return $app->make('log')->channel($channel);
        } catch (Throwable) {
            return null;
        }
    }
}
