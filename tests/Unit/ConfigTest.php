<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Tests\Unit;

use Cbagdawala\InnLogger\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function test_timeouts_default_clamp_and_never_go_below_one_millisecond(): void
    {
        $defaults = new Config([]);
        $this->assertSame(2.0, $defaults->timeout);
        $this->assertSame(1.0, $defaults->connectTimeout);

        $invalid = new Config(['timeout' => 'abc', 'connect_timeout' => 0]);
        $this->assertSame(2.0, $invalid->timeout);
        $this->assertSame(1.0, $invalid->connectTimeout);

        $capped = new Config(['timeout' => 120, 'connect_timeout' => '45']);
        $this->assertSame(30.0, $capped->timeout);
        $this->assertSame(30.0, $capped->connectTimeout);

        // Guzzle 8 throws on positive timeouts below 1 ms, which would make every send fail.
        $tiny = new Config(['timeout' => 0.0001, 'connect_timeout' => '0.0004']);
        $this->assertSame(0.001, $tiny->timeout);
        $this->assertSame(0.001, $tiny->connectTimeout);
    }
}
