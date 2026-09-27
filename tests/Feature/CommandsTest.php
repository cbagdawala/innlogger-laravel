<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
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

        $this->assertCommand('innlogger:test', [], 0, ['Configuration valid.', 'Response status: 202', 'Authentication:  ok', 'Log ID:          LOG-1', 'Event ID:'], [self::SECRET]);

        Http::assertSent(function (Request $request): bool {
            $this->assertValidSignature($request);
            $this->assertSame('innlogger-test', $this->payload($request)['category']);

            return true;
        });
    }

    public function test_innlogger_test_reports_invalid_credentials(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Invalid credentials'], 401)]);

        $this->assertCommand('innlogger:test', [], 1, ['Response status: 401', 'rejected (invalid API key'], []);
    }

    public function test_innlogger_test_reports_unreachable_endpoint(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('Could not resolve host');
        });

        $this->assertCommand('innlogger:test', [], 1, ['Reachable:       no'], []);
    }

    public function test_innlogger_test_rejects_invalid_configuration(): void
    {
        config()->set('innlogger.url', 'http://insecure.test');
        $this->app->forgetInstance(\Cbagdawala\InnLogger\Config::class);
        $this->app->forgetInstance(\Cbagdawala\InnLogger\Client::class);
        Http::fake();

        $this->assertCommand('innlogger:test', [], 1, ['must use https://'], []);

        Http::assertNothingSent();
    }

    public function test_innlogger_status_offline_masks_secrets(): void
    {
        $this->assertCommand('innlogger:status', ['--offline' => true], 0, ['API secret:      set', 'Threshold:       0 OFF (sends: nothing)'], [self::SECRET, self::KEY]);
    }

    public function test_innlogger_status_sends_a_heartbeat(): void
    {
        Http::fake(['*' => Http::response(null, 204)]);

        $this->assertCommand('innlogger:status', [], 0, ['Heartbeat accepted (HTTP 204)'], []);

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

    /**
     * Runs a command and checks its exit code and output. Artisan::output() works on
     * Laravel 8 too, which has no expectsOutputToContain().
     *
     * @param  array<string, mixed>  $parameters
     * @param  list<string>  $contains
     * @param  list<string>  $notContains
     */
    private function assertCommand(string $command, array $parameters, int $exitCode, array $contains, array $notContains): void
    {
        $this->assertSame($exitCode, Artisan::call($command, $parameters));

        $output = Artisan::output();
        foreach ($contains as $text) {
            $this->assertStringContainsString($text, $output);
        }
        foreach ($notContains as $text) {
            $this->assertStringNotContainsString($text, $output);
        }
    }
}
