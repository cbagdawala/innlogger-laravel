<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Transport;

final class TransportResponse
{
    /**
     * @param  array<string, string>  $headers  lower-cased header names
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return array<mixed>
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        try {
            $decoded = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
