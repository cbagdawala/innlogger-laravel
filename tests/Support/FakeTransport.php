<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Tests\Support;

use Cbagdawala\InnLogger\Transport\TransportException;
use Cbagdawala\InnLogger\Transport\TransportInterface;
use Cbagdawala\InnLogger\Transport\TransportResponse;
use Closure;
use Throwable;

/**
 * Records every request and replays queued responses/exceptions
 * (the last one repeats once the queue is exhausted).
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<array{url: string, headers: array<string, string>, body: string, timeout: float, connect_timeout: float}> */
    public array $requests = [];

    /** @var list<TransportResponse|Throwable|Closure> */
    private array $queue;

    public function __construct(TransportResponse|Throwable|Closure ...$responses)
    {
        $this->queue = $responses === [] ? [new TransportResponse(202, '{"success":true,"message":"Log accepted","data":{"log_id":"log-uuid-1"}}')] : array_values($responses);
    }

    public function post(string $url, array $headers, string $body, float $timeout, float $connectTimeout): TransportResponse
    {
        $this->requests[] = [
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
        ];

        $next = count($this->queue) > 1 ? array_shift($this->queue) : $this->queue[0];

        if ($next instanceof Closure) {
            $next = $next($url, $headers, $body);
        }

        if ($next instanceof TransportException) {
            throw $next;
        }

        if ($next instanceof Throwable) {
            throw new TransportException('fake failure', 0, $next);
        }

        return $next;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(int $index = 0): array
    {
        return json_decode($this->requests[$index]['body'], true, 512, JSON_THROW_ON_ERROR);
    }
}
