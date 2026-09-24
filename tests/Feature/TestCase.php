<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Tests\Feature;

use Cbagdawala\InnLogger\Laravel\InnLoggerServiceProvider;
use Illuminate\Http\Client\Request;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const URL = 'https://logger.test';
    public const KEY = 'ilv_FeatureKey0123456789abcdefABCD';
    public const SECRET = 'ils_FeatureSecret0123456789abcdefABCDEF0123456789';

    protected function getPackageProviders($app): array
    {
        return [InnLoggerServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['InnLogger' => \Cbagdawala\InnLogger\Laravel\Facades\InnLogger::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.name', 'trusted-nanny');
        $app['config']->set('innlogger', array_merge([
            'enabled' => true,
            'url' => self::URL,
            'api_key' => self::KEY,
            'api_secret' => self::SECRET,
            'log_level' => 7,
            'environment' => 'testing',
            'retries' => 0,
            'retry_delay_ms' => 0,
            'transport' => 'laravel',
        ], $this->innLoggerConfig()));
    }

    /**
     * @return array<string, mixed>
     */
    protected function innLoggerConfig(): array
    {
        return [];
    }

    /**
     * Recompute the signature independently from the request's raw body.
     */
    protected function assertValidSignature(Request $request): void
    {
        $timestamp = $request->header('X-InnLogger-Timestamp')[0] ?? '';
        $nonce = $request->header('X-InnLogger-Nonce')[0] ?? '';
        $expected = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$request->body(), self::SECRET);

        $this->assertSame($expected, $request->header('X-InnLogger-Signature')[0] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        return json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
    }
}
