<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Transport;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Throwable;

/**
 * Framework-agnostic transport built directly on Guzzle.
 */
final class GuzzleTransport implements TransportInterface
{
    private ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? new GuzzleClient();
    }

    public function post(string $url, array $headers, string $body, float $timeout, float $connectTimeout): TransportResponse
    {
        try {
            $response = $this->client->request('POST', $url, [
                RequestOptions::HEADERS => $headers,
                RequestOptions::BODY => $body,
                RequestOptions::TIMEOUT => $timeout,
                RequestOptions::CONNECT_TIMEOUT => $connectTimeout,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::ALLOW_REDIRECTS => false,
            ]);
        } catch (Throwable $e) {
            throw new TransportException('InnLogger request failed: '.$e::class, 0, $e);
        }

        $responseHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            $responseHeaders[strtolower((string) $name)] = implode(', ', $values);
        }

        return new TransportResponse($response->getStatusCode(), (string) $response->getBody(), $responseHeaders);
    }
}
