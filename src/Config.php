<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger;

/**
 * Immutable, framework-agnostic SDK configuration.
 */
final class Config
{
    public readonly bool $enabled;
    public readonly string $url;
    public readonly string $apiKey;
    /** Never a plain property: see apiSecret() and Secret. */
    private readonly Secret $secret;
    public readonly int $logLevel;
    public readonly float $timeout;
    public readonly float $connectTimeout;
    public readonly ?string $environment;
    public readonly ?string $application;
    public readonly ?string $applicationVersion;
    public readonly ?string $hostname;
    public readonly string $category;
    public readonly int $exceptionLevel;
    public readonly bool $failSilent;
    public readonly bool $allowInsecure;
    public readonly int $retries;
    public readonly int $retryDelayMs;
    /** @var list<string> */
    public readonly array $redactFields;
    /** @var array<string, string> */
    public readonly array $maskPatterns;
    public readonly int $maxMessageBytes;
    public readonly int $maxTraceBytes;
    public readonly int $maxContextBytes;
    public readonly int $maxMetadataBytes;

    /**
     * @param  array<string, mixed>  $config  Same keys as config/innlogger.php.
     */
    public function __construct(array $config)
    {
        $this->enabled = self::bool($config['enabled'] ?? true);
        $this->url = rtrim(trim((string) ($config['url'] ?? '')), '/');
        $this->apiKey = trim((string) ($config['api_key'] ?? ''));
        $this->secret = new Secret((string) ($config['api_secret'] ?? ''));
        $this->logLevel = max(0, min(Severity::TRACE, (int) ($config['log_level'] ?? Severity::ERROR)));
        $this->timeout = self::positiveFloat($config['timeout'] ?? 2, 2.0);
        $this->connectTimeout = self::positiveFloat($config['connect_timeout'] ?? 1, 1.0);
        $this->environment = self::nullableString($config['environment'] ?? null);
        $this->application = self::nullableString($config['application'] ?? null);
        $this->applicationVersion = self::nullableString($config['application_version'] ?? null);
        $this->hostname = self::nullableString($config['hostname'] ?? null);
        $this->category = self::nullableString($config['category'] ?? null) ?? 'application';
        $exceptionLevel = (int) ($config['exception_level'] ?? Severity::ERROR);
        $this->exceptionLevel = Severity::isValid($exceptionLevel) ? $exceptionLevel : Severity::ERROR;
        $this->failSilent = self::bool($config['fail_silent'] ?? true);
        $this->allowInsecure = self::bool($config['allow_insecure'] ?? false);
        $this->retries = max(0, min(5, (int) ($config['retries'] ?? 1)));
        $this->retryDelayMs = max(0, min(5000, (int) ($config['retry_delay_ms'] ?? 100)));
        $this->redactFields = array_values(array_filter(
            array_map(static fn ($key): string => (string) $key, (array) ($config['redact_fields'] ?? [])),
            static fn (string $key): bool => $key !== '',
        ));
        $patterns = [];
        foreach ((array) ($config['mask_patterns'] ?? []) as $pattern => $replacement) {
            if (is_string($pattern) && $pattern !== '') {
                $patterns[$pattern] = (string) $replacement;
            }
        }
        $this->maskPatterns = $patterns;
        $limits = (array) ($config['limits'] ?? []);
        $this->maxMessageBytes = max(256, (int) ($limits['message'] ?? 16384));
        $this->maxTraceBytes = max(1024, (int) ($limits['trace'] ?? 65536));
        $this->maxContextBytes = max(1024, (int) ($limits['context'] ?? 65536));
        $this->maxMetadataBytes = max(1024, (int) ($limits['metadata'] ?? 65536));
    }

    /**
     * Returns null when the configuration can be used to send, otherwise a
     * short machine-readable reason. Never includes secret values.
     */
    public function problem(): ?string
    {
        if ($this->url === '' || $this->apiKey === '' || $this->secret->isEmpty()) {
            return 'not_configured';
        }

        $scheme = strtolower((string) parse_url($this->url, PHP_URL_SCHEME));
        $host = parse_url($this->url, PHP_URL_HOST);

        if (! is_string($host) || $host === '' || ! in_array($scheme, ['https', 'http'], true)) {
            return 'invalid_url';
        }

        if ($scheme === 'http' && ! $this->allowInsecure) {
            return 'insecure_url';
        }

        return null;
    }

    /** The raw signing secret. Only the Signer should need this. */
    public function apiSecret(): string
    {
        return $this->secret->reveal();
    }

    public function hasApiSecret(): bool
    {
        return ! $this->secret->isEmpty();
    }

    /**
     * var_dump()/print_r()/debug bars: every value, with the secret masked.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $values = get_object_vars($this);
        unset($values['secret']);

        return ['apiSecret' => $this->secret->isEmpty() ? '(not set)' : Secret::MASK] + $values;
    }

    /**
     * Serialized copies never carry the secret (the Secret serializes empty).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        foreach ($data as $property => $value) {
            if (property_exists($this, $property)) {
                $this->{$property} = $value;
            }
        }
        if (! isset($this->secret)) {
            $this->secret = new Secret('');
        }
    }

    public function endpoint(string $path): string
    {
        return $this->url.'/api/v1/'.ltrim($path, '/');
    }

    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private static function positiveFloat(mixed $value, float $default): float
    {
        $float = is_numeric($value) ? (float) $value : $default;

        return $float > 0 ? min($float, 30.0) : $default;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
