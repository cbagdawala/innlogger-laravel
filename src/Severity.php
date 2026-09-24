<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger;

/**
 * InnLogger severity scale. Lower values are more severe.
 */
final class Severity
{
    public const OFF = 0;
    public const CRITICAL = 1;
    public const ERROR = 2;
    public const WARNING = 3;
    public const NOTICE = 4;
    public const INFO = 5;
    public const DEBUG = 6;
    public const TRACE = 7;

    private const NAMES = [
        self::OFF => 'OFF',
        self::CRITICAL => 'CRITICAL',
        self::ERROR => 'ERROR',
        self::WARNING => 'WARNING',
        self::NOTICE => 'NOTICE',
        self::INFO => 'INFO',
        self::DEBUG => 'DEBUG',
        self::TRACE => 'TRACE',
    ];

    /** PSR-3 / Monolog level names mapped onto the InnLogger scale. */
    private const ALIASES = [
        'emergency' => self::CRITICAL,
        'alert' => self::CRITICAL,
        'critical' => self::CRITICAL,
        'error' => self::ERROR,
        'warning' => self::WARNING,
        'notice' => self::NOTICE,
        'info' => self::INFO,
        'debug' => self::DEBUG,
        'trace' => self::TRACE,
        'off' => self::OFF,
    ];

    private function __construct()
    {
    }

    public static function name(int $level): string
    {
        return self::NAMES[$level] ?? 'UNKNOWN';
    }

    public static function isValid(int $level): bool
    {
        return $level >= self::CRITICAL && $level <= self::TRACE;
    }

    /**
     * Should an event of $level be transmitted with $threshold configured?
     * Threshold 0 (or below) disables transmission entirely.
     */
    public static function shouldSend(int $level, int $threshold): bool
    {
        if ($threshold <= self::OFF || ! self::isValid($level)) {
            return false;
        }

        return $level <= min($threshold, self::TRACE);
    }

    /**
     * Resolve an int, numeric string or PSR-3/InnLogger level name to a severity.
     * Returns null when it cannot be resolved.
     */
    public static function fromMixed(int|string $level): ?int
    {
        if (is_int($level)) {
            return self::isValid($level) ? $level : null;
        }

        if (is_numeric($level)) {
            return self::fromMixed((int) $level);
        }

        return self::ALIASES[strtolower(trim($level))] ?? null;
    }
}
