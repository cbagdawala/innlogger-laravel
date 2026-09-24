<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Tests\Feature;

use Cbagdawala\InnLogger\Client;
use Cbagdawala\InnLogger\Laravel\Facades\InnLogger;
use Cbagdawala\InnLogger\Uuid;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class FacadeTest extends TestCase
{
    public function test_facade_resolves_the_singleton_client(): void
    {
        $this->assertInstanceOf(Client::class, InnLogger::getFacadeRoot());
        $this->assertSame(app(Client::class), app('innlogger'));
    }

    public function test_facade_sends_a_signed_event_through_the_laravel_http_client(): void
    {
        Http::fake(['logger.test/*' => Http::response(['success' => true, 'message' => 'Log accepted', 'data' => ['log_id' => 'L1']], 202)]);

        $result = InnLogger::error('Payment failed', ['category' => 'payment', 'amount' => 10, 'password' => 'secret-pw']);

        $this->assertTrue($result->successful());
        $this->assertSame('L1', $result->logId);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $this->assertSame('POST', $request->method());
            $this->assertSame(self::URL.'/api/v1/logs', $request->url());
            $this->assertSame(self::KEY, $request->header('X-InnLogger-Key')[0]);
            $this->assertNotEmpty($request->header('X-InnLogger-Nonce')[0]);
            $this->assertNotEmpty($request->header('X-InnLogger-Request-Id')[0]);
            $this->assertStringContainsString('application/json', $request->header('Content-Type')[0]);
            $this->assertValidSignature($request);

            $payload = $this->payload($request);
            $this->assertTrue(Uuid::isV4($payload['event_id']));
            $this->assertSame(2, $payload['level']);
            $this->assertSame('payment', $payload['category']);
            $this->assertSame('testing', $payload['environment']);
            $this->assertSame('trusted-nanny', $payload['application']);
            $this->assertSame('[REDACTED]', $payload['context']['password']);
            $this->assertStringNotContainsString(self::SECRET, $request->body());

            return true;
        });
    }

    public function test_all_facade_severity_methods(): void
    {
        Http::fake(['*' => Http::response([], 202)]);

        InnLogger::critical('a');
        InnLogger::error('b');
        InnLogger::warning('c');
        InnLogger::notice('d');
        InnLogger::info('e');
        InnLogger::debug('f');
        InnLogger::trace('g');
        InnLogger::exception(new RuntimeException('h'));

        $levels = [];
        foreach (Http::recorded() as [$request]) {
            $levels[] = $this->payload($request)['level'];
        }

        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 2], $levels);
    }

    public function test_connection_failure_is_swallowed(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out after 2001 milliseconds');
        });

        $result = InnLogger::critical('outage');

        $this->assertSame('failed', $result->status);
        $this->assertSame('transport_error', $result->reason);
    }

    public function test_threshold_applies_to_the_facade(): void
    {
        config()->set('innlogger.log_level', 2);
        app()->forgetInstance(Client::class);
        app()->forgetInstance(\Cbagdawala\InnLogger\Config::class);
        InnLogger::clearResolvedInstances();
        Http::fake(['*' => Http::response([], 202)]);

        InnLogger::warning('not sent');
        InnLogger::error('sent');

        Http::assertSentCount(1);
    }
}
