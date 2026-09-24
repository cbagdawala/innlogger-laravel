<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger;

/**
 * Builds the InnLogger HMAC authentication headers.
 *
 * signature = lowercase hex HMAC-SHA256(timestamp . "\n" . nonce . "\n" . raw_body, api_secret)
 */
final class Signer
{
    private readonly Secret $secret;

    public function __construct(
        private readonly string $apiKey,
        string $apiSecret,
    ) {
        $this->secret = new Secret($apiSecret);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['apiKey' => $this->apiKey, 'apiSecret' => $this->secret->isEmpty() ? '(not set)' : Secret::MASK];
    }

    public static function signature(string $timestamp, string $nonce, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $timestamp."\n".$nonce."\n".$rawBody, $secret);
    }

    public static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @return array<string, string>
     */
    public function headers(string $rawBody, string $requestId, ?int $timestamp = null, ?string $nonce = null): array
    {
        $timestamp = (string) ($timestamp ?? time());
        $nonce ??= self::nonce();

        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-InnLogger-Key' => $this->apiKey,
            'X-InnLogger-Timestamp' => $timestamp,
            'X-InnLogger-Nonce' => $nonce,
            'X-InnLogger-Signature' => self::signature($timestamp, $nonce, $rawBody, $this->secret->reveal()),
            'X-InnLogger-Request-Id' => $requestId,
        ];
    }
}
