<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Tests\Feature;

use Cbagdawala\InnLogger\Laravel\Logging\CreateInnLoggerLogger;
use Cbagdawala\InnLogger\Laravel\Logging\InnLoggerHandler;
use Cbagdawala\InnLogger\Laravel\Logging\InnLoggerMonolog2Handler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class LogChannelTest extends TestCase
{
    protected function innLoggerConfig(): array
    {
        return ['log_level' => 3];
    }

    public function test_channel_is_registered_automatically(): void
    {
        $this->assertSame('innlogger', config('logging.channels.innlogger.driver'));
    }

    public function test_log_channel_normalizes_records_into_events(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['log_id' => 'L']], 202)]);

        Log::channel('innlogger')->error('Payment failed', [
            'category' => 'payment',
            'order_id' => 7,
            'card_number' => '4111111111111111',
        ]);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $this->assertValidSignature($request);
            $payload = $this->payload($request);
            $this->assertSame(2, $payload['level']);
            $this->assertSame('ERROR', $payload['level_name']);
            $this->assertSame('Payment failed', $payload['message']);
            $this->assertSame('payment', $payload['category']);
            $this->assertSame(['order_id' => 7, 'card_number' => '[REDACTED]'], $payload['context']);
            $this->assertSame('ERROR', $payload['metadata']['monolog_level']);
            $this->assertSame(__FILE__, $payload['file']);

            return true;
        });
    }

    public function test_monolog_levels_map_and_threshold_applies(): void
    {
        Http::fake(['*' => Http::response([], 202)]);

        $log = Log::channel('innlogger');
        $log->emergency('e');
        $log->alert('a');
        $log->critical('c');
        $log->warning('w');
        $log->notice('below threshold');
        $log->info('below threshold');
        $log->debug('below threshold');

        $levels = [];
        foreach (Http::recorded() as [$request]) {
            $levels[] = $this->payload($request)['level'];
        }

        $this->assertSame([1, 1, 1, 3], $levels);
    }

    public function test_exception_in_context_is_normalized(): void
    {
        Http::fake(['*' => Http::response([], 202)]);
        $exception = new InvalidArgumentException('Bad input');

        Log::channel('innlogger')->error('Something broke', ['exception' => $exception]);

        Http::assertSent(function (Request $request) use ($exception): bool {
            $payload = $this->payload($request);
            $this->assertSame(InvalidArgumentException::class, $payload['exception']['class']);
            $this->assertSame('Bad input', $payload['exception']['message']);
            $this->assertSame($exception->getLine(), $payload['exception']['line']);
            $this->assertSame('exception', $payload['category']);
            $this->assertArrayNotHasKey('exception', $payload['context']);

            return true;
        });
    }

    public function test_log_channel_never_throws_when_innlogger_is_down(): void
    {
        Http::fake(static function (): never {
            throw new \RuntimeException('network down');
        });

        Log::channel('innlogger')->critical('still fine');

        $this->assertTrue(true);
    }

    public function test_stack_channel_including_innlogger(): void
    {
        Http::fake(['*' => Http::response([], 202)]);
        config()->set('logging.channels.app_stack', ['driver' => 'stack', 'channels' => ['innlogger']]);

        Log::channel('app_stack')->error('via stack');

        Http::assertSentCount(1);
    }

    public function test_handler_matches_the_installed_monolog(): void
    {
        $handlers = Log::channel('innlogger')->getLogger()->getHandlers();

        $this->assertCount(1, $handlers);
        $this->assertInstanceOf(
            class_exists(\Monolog\LogRecord::class) ? InnLoggerHandler::class : InnLoggerMonolog2Handler::class,
            $handlers[0],
        );
    }

    public function test_custom_via_factory(): void
    {
        Http::fake(['*' => Http::response([], 202)]);
        config()->set('logging.channels.innlogger_custom', [
            'driver' => 'custom',
            'via' => CreateInnLoggerLogger::class,
            'level' => 'error',
        ]);

        Log::channel('innlogger_custom')->warning('filtered by channel level');
        Log::channel('innlogger_custom')->error('sent');

        Http::assertSentCount(1);
    }
}
