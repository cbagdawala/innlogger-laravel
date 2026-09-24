<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class CommandsTest extends TestCase
{
    protected function innLoggerConfig(): array
    {
        // The test command bypasses the threshold.
        return ['log_level' => 0];
    }

    public function test_innlogger_test_reports_success(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['log_id' => 'LOG-1']], 202)]);

        $this->artisan('innlogger:test')
            ->expectsOutputToContain('Configuration valid.')
            ->expectsOutputToContain('Response status: 202')
            ->expectsOutputToContain('Authentication:  ok')
            ->expectsOutputToContain('Log ID:          LOG-1')
            ->expectsOutputToContain('Event ID:')
            ->doesntExpectOutputToContain(self::SECRET)
            ->assertExitCode(0);

        Http::assertSent(function (Request $request): bool {
            $this->assertValidSignature($request);
            $this->assertSame('innlogger-test', $this->payload($request)['category']);

            return true;
        });
    }

    public function test_innlogger_test_reports_invalid_credentials(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Invalid credentials'], 401)]);

        $this->artisan('innlogger:test')
            ->expectsOutputToContain('Response status: 401')
            ->expectsOutputToContain('rejected (invalid API key')
            ->assertExitCode(1);
    }

    public function test_innlogger_test_reports_unreachable_endpoint(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('Could not resolve host');
        });

        $this->artisan('innlogger:test')
            ->expectsOutputToContain('Reachable:       no')
            ->assertExitCode(1);
    }

    public function test_innlogger_test_rejects_invalid_configuration(): void
    {
        config()->set('innlogger.url', 'http://insecure.test');
        $this->app->forgetInstance(\Cbagdawala\InnLogger\Config::class);
        $this->app->forgetInstance(\Cbagdawala\InnLogger\Client::class);
        Http::fake();

        $this->artisan('innlogger:test')
            ->expectsOutputToContain('must use https://')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_innlogger_status_offline_masks_secrets(): void
    {
        $this->artisan('innlogger:status', ['--offline' => true])
            ->expectsOutputToContain('API secret:      set')
            ->expectsOutputToContain('Threshold:       0 OFF (sends: nothing)')
            ->doesntExpectOutputToContain(self::SECRET)
            ->doesntExpectOutputToContain(self::KEY)
            ->assertExitCode(0);
    }

    public function test_innlogger_status_sends_a_heartbeat(): void
    {
        Http::fake(['*' => Http::response(null, 204)]);

        $this->artisan('innlogger:status')
            ->expectsOutputToContain('Heartbeat accepted (HTTP 204)')
            ->assertExitCode(0);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame(self::URL.'/api/v1/heartbeat', $request->url());
            $this->assertValidSignature($request);
            $this->assertSame('testing', $this->payload($request)['environment']);

            return true;
        });
    }

    public function test_innlogger_heartbeat_command(): void
    {
        Http::fake(['*' => Http::response(null, 204)]);

        $this->artisan('innlogger:heartbeat')->assertExitCode(0);

        Http::assertSentCount(1);
    }
}
