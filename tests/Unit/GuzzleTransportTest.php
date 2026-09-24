<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Tests\Unit;

use Cbagdawala\InnLogger\Transport\GuzzleTransport;
use Cbagdawala\InnLogger\Transport\TransportException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class GuzzleTransportTest extends TestCase
{
    public function test_sends_exact_body_and_options_and_maps_the_response(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(503, ['Retry-After' => '5'], '{"success":false}')]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $response = (new GuzzleTransport(new Client(['handler' => $stack])))
            ->post('https://logger.test/api/v1/logs', ['X-InnLogger-Key' => 'k'], '{"a":1}', 1.5, 0.5);

        $this->assertSame(503, $response->status);
        $this->assertSame('5', $response->header('Retry-After'));
        $this->assertSame(['success' => false], $response->json());

        $this->assertSame('{"a":1}', (string) $history[0]['request']->getBody());
        $this->assertSame('k', $history[0]['request']->getHeaderLine('X-InnLogger-Key'));
        $this->assertSame(1.5, $history[0]['options']['timeout']);
        $this->assertSame(0.5, $history[0]['options']['connect_timeout']);
        $this->assertFalse($history[0]['options']['allow_redirects']);
    }

    public function test_timeouts_become_transport_exceptions(): void
    {
        $mock = new MockHandler([
            new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'https://logger.test')),
        ]);

        $this->expectException(TransportException::class);

        (new GuzzleTransport(new Client(['handler' => HandlerStack::create($mock)])))
            ->post('https://logger.test/api/v1/logs', [], '{}', 0.1, 0.1);
    }
}
