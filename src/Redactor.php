<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger;

use BackedEnum;
use DateTimeInterface;
use JsonSerializable;
use Throwable;
use UnitEnum;

/**
 * Makes arbitrary context data JSON-safe and removes secrets from it.
 *
 * - Values of sensitive keys are replaced with "[REDACTED]" at any depth.
 *   Key matching is case-insensitive and treats "-" and "_" as the same.
 * - Every string value is passed through the masking rules (bearer/basic
 *   credentials, InnLogger secrets, the configured API secret and any custom
 *   regex rules).
 * - Objects are converted to arrays/strings; unknown objects become a
 *   "[object Class]" placeholder, resources "[resource]".
 */
final class Redactor
{
    public const REDACTED = '[REDACTED]';

    /** Always redacted, in addition to the configured list (spec 09 §5). */
    public const DEFAULT_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'token',
        'access_token',
        'refresh_token',
        'id_token',
        'authorization',
        'proxy_authorization',
        'cookie',
        'set_cookie',
        'card_number',
        'cvv',
        'cvc',
        'secret',
        'api_secret',
        'client_secret',
        'api_key',
        'private_key',
        'csrf_token',
        'xsrf_token',
        'x_xsrf_token',
        '_token',
        'x_innlogger_signature',
    ];

    public const MAX_DEPTH = 10;

    /** @var array<string, true> */
    private array $keys = [];

    /** @var array<string, string> */
    private array $patterns;

    /** @var list<Secret> exact secret strings, held so they never show in dumps */
    private array $literals;

    /**
     * @param  iterable<string>  $extraKeys
     * @param  array<string, string>  $maskPatterns  regex => replacement
     * @param  list<string>  $literalSecrets  exact strings that must never be sent
     */
    public function __construct(iterable $extraKeys = [], array $maskPatterns = [], array $literalSecrets = [])
    {
        foreach ([...self::DEFAULT_KEYS, ...$extraKeys] as $key) {
            $normalized = self::normalizeKey((string) $key);
            if ($normalized !== '') {
                $this->keys[$normalized] = true;
            }
        }

        $this->patterns = [
            '/\b(Bearer|Basic|Digest)\s+[A-Za-z0-9\-._~+\/]+=*/i' => '$1 '.self::REDACTED,
            '/\bils_[A-Za-z0-9]{8,}/' => self::REDACTED,
        ] + $maskPatterns;

        $this->literals = array_values(array_map(
            static fn (string $secret): Secret => new Secret($secret),
            array_filter($literalSecrets, static fn (string $secret): bool => strlen($secret) >= 4),
        ));
    }

    public static function normalizeKey(string $key): string
    {
        return str_replace('-', '_', strtolower(trim($key)));
    }

    public function isSensitiveKey(int|string $key): bool
    {
        return is_string($key) && isset($this->keys[self::normalizeKey($key)]);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public function redact(array $data): array
    {
        $result = $this->value($data, 0);

        return is_array($result) ? $result : [];
    }

    public function maskString(string $value): string
    {
        foreach ($this->literals as $secret) {
            $literal = $secret->reveal();
            if ($literal !== '' && str_contains($value, $literal)) {
                $value = str_replace($literal, self::REDACTED, $value);
            }
        }

        foreach ($this->patterns as $pattern => $replacement) {
            $masked = @preg_replace($pattern, $replacement, $value);
            if (is_string($masked)) {
                $value = $masked;
            }
        }

        return $value;
    }

    /**
     * Redact the values of sensitive query-string parameters, e.g.
     * /reset?token=abc&page=2 -> /reset?token=[REDACTED]&page=2
     */
    public function redactUrl(string $url): string
    {
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        $question = strpos($url, '?');
        if ($question === false) {
            return $this->maskString($url.$fragment);
        }

        $base = substr($url, 0, $question);
        $pairs = explode('&', substr($url, $question + 1));

        foreach ($pairs as $i => $pair) {
            $name = urldecode(explode('=', $pair, 2)[0]);
            preg_match_all('/[^\[\]]+/', $name, $segments);
            foreach ($segments[0] as $segment) {
                if ($this->isSensitiveKey($segment)) {
                    $pairs[$i] = explode('=', $pair, 2)[0].'='.self::REDACTED;
                    break;
                }
            }
        }

        return $this->maskString($base.'?'.implode('&', $pairs).$fragment);
    }

    private function value(mixed $value, int $depth): mixed
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : (string) $value;
        }

        if (is_string($value)) {
            return $this->maskString($value);
        }

        if ($depth >= self::MAX_DEPTH) {
            return '[max depth]';
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->isSensitiveKey($key) ? self::REDACTED : $this->value($item, $depth + 1);
            }

            return $out;
        }

        if (is_object($value)) {
            return $this->object($value, $depth);
        }

        return '[resource]';
    }

    private function object(object $value, int $depth): mixed
    {
        try {
            if ($value instanceof Throwable) {
                return [
                    'class' => $value::class,
                    'message' => $this->maskString($value->getMessage()),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                ];
            }

            if ($value instanceof DateTimeInterface) {
                return $value->format(DateTimeInterface::ATOM);
            }

            if ($value instanceof BackedEnum) {
                return $this->value($value->value, $depth + 1);
            }

            if ($value instanceof UnitEnum) {
                return $value->name;
            }

            if ($value instanceof JsonSerializable) {
                return $this->value($value->jsonSerialize(), $depth + 1);
            }

            if (method_exists($value, 'toArray')) {
                $array = $value->toArray();
                if (is_array($array)) {
                    return $this->value($array, $depth + 1);
                }
            }

            if ($value instanceof \stdClass) {
                return $this->value(get_object_vars($value), $depth + 1);
            }

            if ($value instanceof \Stringable) {
                return $this->maskString((string) $value);
            }
        } catch (Throwable) {
            // Fall through to the placeholder: a misbehaving object must not break logging.
        }

        return '[object '.$value::class.']';
    }
}
