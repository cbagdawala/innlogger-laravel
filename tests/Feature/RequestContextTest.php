<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Tests\Feature;

use Cbagdawala\InnLogger\Laravel\Facades\InnLogger;
use Cbagdawala\InnLogger\Laravel\Http\CaptureRequestContext;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

final class RequestContextTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->post('/api/payment', static function () {
            InnLogger::error('Payment failed', ['order_id' => 9]);

            return response()->json(['ok' => false], 500);
        })->middleware(CaptureRequestContext::class)->name('payment.store');
    }

    public function test_request_context_is_captured_without_sensitive_data(): void
    {
        Http::fake(['*' => Http::response([], 202)]);
        $this->actingAs(new GenericUser(['id' => 42]));

        $this->withHeaders([
            'X-Request-Id' => 'req_abc123',
            'Authorization' => 'Bearer top-secret-token',
            'Cookie' => 'laravel_session=session-cookie-value',
        ])->postJson('/api/payment?token=query-secret&page=2', ['password' => 'body-password', 'card_number' => '4111'])
            ->assertStatus(500);

        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $request): bool {
            $body = $request->body();
            $payload = $this->payload($request);

            $this->assertSame('req_abc123', $payload['request_id']);
            $this->assertSame('POST', $payload['http_method']);
            $this->assertSame('/api/payment?token=[REDACTED]&page=2', $payload['url']);
            $this->assertSame(42, $payload['user_id']);
            $this->assertSame('payment.store', $payload['metadata']['route']);
            $this->assertNotEmpty($payload['hostname']);
            $this->assertSame(['order_id' => 9], $payload['context']);

            foreach (['top-secret-token', 'session-cookie-value', 'query-secret', 'body-password', '4111'] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }

            return true;
        });
    }

    public function test_request_id_is_generated_when_absent(): void
    {
        Http::fake(['*' => Http::response([], 202)]);

        $this->postJson('/api/payment')->assertStatus(500);

        Http::assertSent(function (ClientRequest $request): bool {
            $payload = $this->payload($request);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $payload['request_id']);
            $this->assertArrayNotHasKey('user_id', $payload);

            return true;
        });
    }

    public function test_capture_can_be_disabled(): void
    {
        config()->set('innlogger.capture.request', false);
        $this->app->forgetInstance(\Cbagdawala\InnLogger\Client::class);
        InnLogger::clearResolvedInstances();
        Http::fake(['*' => Http::response([], 202)]);

        $this->postJson('/api/payment')->assertStatus(500);

        Http::assertSent(function (ClientRequest $request): bool {
            $payload = $this->payload($request);
            $this->assertArrayNotHasKey('url', $payload);
            $this->assertArrayNotHasKey('request_id', $payload);

            return true;
        });
    }
}
