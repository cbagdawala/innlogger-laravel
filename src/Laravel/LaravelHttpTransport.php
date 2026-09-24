<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Laravel;

use Cbagdawala\InnLogger\Transport\TransportException;
use Cbagdawala\InnLogger\Transport\TransportInterface;
use Cbagdawala\InnLogger\Transport\TransportResponse;
use Closure;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Transport on Laravel's HTTP client, so host apps can use Http::fake().
 * The factory is resolved on every send, which keeps Http::fake() swaps working.
 */
final class LaravelHttpTransport implements TransportInterface
{
    /**
     * @param  Closure(): Factory  $factory
     */
    public function __construct(private readonly Closure $factory)
    {
    }

    public function post(string $url, array $headers, string $body, float $timeout, float $connectTimeout): TransportResponse
    {
        try {
            /** @var Factory $http */
            $http = ($this->factory)();

            $response = $http
                ->withOptions([
                    'timeout' => $timeout,
                    'connect_timeout' => $connectTimeout,
                    'allow_redirects' => false,
                    'http_errors' => false,
                ])
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (Throwable $e) {
            throw new TransportException('InnLogger request failed: '.$e::class, 0, $e);
        }

        $responseHeaders = [];
        foreach ($response->headers() as $name => $values) {
            $responseHeaders[strtolower((string) $name)] = is_array($values) ? implode(', ', $values) : (string) $values;
        }

        return new TransportResponse($response->status(), $response->body(), $responseHeaders);
    }
}
