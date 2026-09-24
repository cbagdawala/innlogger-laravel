<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Tests\Unit;

use Cbagdawala\InnLogger\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SeverityTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function matrix(): iterable
    {
        yield 'threshold 0 sends nothing (critical)' => [Severity::CRITICAL, 0, false];
        yield 'threshold 0 sends nothing (trace)' => [Severity::TRACE, 0, false];
        yield 'threshold 2 sends critical' => [Severity::CRITICAL, 2, true];
        yield 'threshold 2 sends error' => [Severity::ERROR, 2, true];
        yield 'threshold 2 drops warning' => [Severity::WARNING, 2, false];
        yield 'threshold 5 sends info' => [Severity::INFO, 5, true];
        yield 'threshold 5 drops debug' => [Severity::DEBUG, 5, false];
        yield 'threshold 7 sends trace' => [Severity::TRACE, 7, true];
        yield 'level 0 is never an event' => [Severity::OFF, 7, false];
        yield 'negative threshold is off' => [Severity::CRITICAL, -1, false];
    }

    #[DataProvider('matrix')]
    public function test_threshold(int $level, int $threshold, bool $expected): void
    {
        $this->assertSame($expected, Severity::shouldSend($level, $threshold));
    }

    public function test_names_and_aliases(): void
    {
        $this->assertSame('ERROR', Severity::name(2));
        $this->assertSame('TRACE', Severity::name(7));
        $this->assertSame(Severity::CRITICAL, Severity::fromMixed('emergency'));
        $this->assertSame(Severity::CRITICAL, Severity::fromMixed('ALERT'));
        $this->assertSame(Severity::WARNING, Severity::fromMixed('warning'));
        $this->assertSame(Severity::DEBUG, Severity::fromMixed('6'));
        $this->assertNull(Severity::fromMixed(9));
        $this->assertNull(Severity::fromMixed('verbose'));
    }
}
