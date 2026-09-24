<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class AutoExceptionTest extends TestCase
{
    protected function innLoggerConfig(): array
    {
        return ['auto_exception' => true];
    }

    public function test_reported_exceptions_are_sent_and_laravel_still_logs_them(): void
    {
        Http::fake(['*' => Http::response([], 202)]);
        Log::spy();

        report(new RuntimeException('Gateway timeout'));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $this->assertValidSignature($request);
            $payload = $this->payload($request);
            $this->assertSame(RuntimeException::class, $payload['exception']['class']);
            $this->assertSame('Gateway timeout', $payload['exception']['message']);
            $this->assertSame(2, $payload['level']);

            return true;
        });

        // Laravel's own reporting still ran.
        Log::shouldHaveReceived('error')->once();
    }

    public function test_innlogger_failure_does_not_stop_laravel_handling(): void
    {
        Http::fake(static function (): never {
            throw new RuntimeException('InnLogger unreachable');
        });
        Log::spy();

        $handler = $this->app->make(ExceptionHandler::class);
        $handler->report(new RuntimeException('original'));

        Log::shouldHaveReceived('error')->once();
    }

    public function test_exception_is_not_sent_twice_when_the_innlogger_channel_is_also_in_the_stack(): void
    {
        Http::fake(['*' => Http::response([], 202)]);
        config()->set('logging.default', 'app_stack');
        config()->set('logging.channels.app_stack', ['driver' => 'stack', 'channels' => ['innlogger']]);

        report(new RuntimeException('once only'));

        Http::assertSentCount(1);
    }

    public function test_ignored_exceptions_are_not_sent(): void
    {
        Http::fake(['*' => Http::response([], 202)]);

        report(new \Illuminate\Validation\ValidationException(validator([], [])));

        Http::assertNothingSent();
    }
}
