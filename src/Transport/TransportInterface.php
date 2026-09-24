<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Transport;

interface TransportInterface
{
    /**
     * POST the exact $body bytes with $headers.
     *
     * Must return a response for any HTTP status (never throw on 4xx/5xx)
     * and must not follow redirects.
     *
     * @param  array<string, string>  $headers
     *
     * @throws TransportException when no HTTP response was received
     *                            (DNS, connect, TLS, timeout, ...)
     */
    public function post(string $url, array $headers, string $body, float $timeout, float $connectTimeout): TransportResponse;
}
