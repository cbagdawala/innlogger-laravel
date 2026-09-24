<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger;

/**
 * Holds a secret without exposing it to var_dump(), print_r(), var_export(),
 * debug bars, json_encode() or serialize().
 *
 * The value lives in a private static map keyed by object id, so the object
 * itself has no property containing it (var_export() ignores __debugInfo()).
 * Serialization deliberately drops the value: an unserialized Secret is empty.
 */
final class Secret
{
    public const MASK = '[REDACTED]';

    /** @var array<int, string> */
    private static array $values = [];

    private int $id;

    public function __construct(string $value)
    {
        $this->id = spl_object_id($this);
        self::$values[$this->id] = $value;
    }

    public function reveal(): string
    {
        return self::$values[$this->id] ?? '';
    }

    public function isEmpty(): bool
    {
        return $this->reveal() === '';
    }

    public function __clone()
    {
        $value = $this->reveal();
        $this->id = spl_object_id($this);
        self::$values[$this->id] = $value;
    }

    public function __destruct()
    {
        unset(self::$values[$this->id]);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => $this->isEmpty() ? '(not set)' : self::MASK];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        $this->id = spl_object_id($this);
        self::$values[$this->id] = '';
    }
}
