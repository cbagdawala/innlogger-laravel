<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Tests\Unit;

use Cbagdawala\InnLogger\Import\LaravelLogParser;
use Cbagdawala\InnLogger\Import\LogEntry;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LaravelLogParserTest extends TestCase
{
    /**
     * Laravel 8 writes " [] []" for empty context/extra; Laravel 10+ omits them.
     * Exception strings are JSON-escaped (\\ and \") but keep real line breaks.
     */
    public const SAMPLE = <<<'LOG'
        garbage before the first entry
        [2026-09-20 08:00:00] production.INFO: Started [] []
        [2026-09-20 08:00:01] production.ERROR: Payment failed {"order_id":7,"category":"payment"} []
        [2026-09-20 08:00:02] local.WARNING: Multi
        line message
        [2026-09-20 08:00:03] production.ERROR: Division by zero {"userId":3,"exception":"[object] (DivisionByZeroError(code: 0): Division by zero at /var/www/app/Http/Controllers/HomeController.php:12)
        [stacktrace]
        #0 /var/www/vendor/Controller.php(54): App\\Http\\Controllers\\HomeController->index()
        #1 {main}
        "} []
        [2026-09-20T08:00:04.123456+00:00] production.CRITICAL: Brace { not json
        [2026-09-20 08:00:05] production.ERROR: Namespaced {"exception":"[object] (App\\Exceptions\\PaymentException(code: 42): Card \"x\" declined at /app/P.php:9)
        [stacktrace]
        #0 {main}
        "}
        LOG;

    /**
     * @return list<LogEntry>
     */
    private function parse(string $contents, string $timezone = 'UTC'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'innlog');
        file_put_contents($path, $contents);

        try {
            return iterator_to_array((new LaravelLogParser(new DateTimeZone($timezone)))->entries($path), false);
        } finally {
            unlink($path);
        }
    }

    public function test_it_splits_entries_and_ignores_lines_before_the_first_header(): void
    {
        $entries = $this->parse(self::SAMPLE);

        $this->assertCount(6, $entries);
        $this->assertSame(['INFO', 'ERROR', 'WARNING', 'ERROR', 'CRITICAL', 'ERROR'], array_map(static fn (LogEntry $e) => $e->levelName, $entries));
        $this->assertSame([2, 3, 4, 6, 11, 12], array_map(static fn (LogEntry $e) => $e->lineNumber, $entries));
    }

    public function test_plain_entries_keep_message_and_context(): void
    {
        [$started, $payment, $multi] = $this->parse(self::SAMPLE);

        $this->assertSame('Started', $started->message);
        $this->assertSame([], $started->context);
        $this->assertSame('production', $started->environment);
        $this->assertNull($started->exception);

        $this->assertSame('Payment failed', $payment->message);
        $this->assertSame(['order_id' => 7, 'category' => 'payment'], $payment->context);

        $this->assertSame("Multi\nline message", $multi->message);
        $this->assertSame('local', $multi->environment);
    }

    public function test_exceptions_are_rebuilt_with_their_trace(): void
    {
        $entries = $this->parse(self::SAMPLE);
        $division = $entries[3];
        $namespaced = $entries[5];

        $this->assertSame('Division by zero', $division->message);
        $this->assertSame(['userId' => 3], $division->context);
        $this->assertSame([
            'class' => 'DivisionByZeroError',
            'message' => 'Division by zero',
            'file' => '/var/www/app/Http/Controllers/HomeController.php',
            'line' => 12,
            'trace' => "#0 /var/www/vendor/Controller.php(54): App\\Http\\Controllers\\HomeController->index()\n#1 {main}",
        ], $division->exception);

        $this->assertSame('Namespaced', $namespaced->message);
        $this->assertSame([], $namespaced->context);
        $this->assertSame('App\\Exceptions\\PaymentException', $namespaced->exception['class'] ?? null);
        $this->assertSame('Card "x" declined', $namespaced->exception['message'] ?? null);
        $this->assertSame(9, $namespaced->exception['line'] ?? null);
    }

    public function test_a_brace_that_is_not_json_stays_in_the_message(): void
    {
        $brace = $this->parse(self::SAMPLE)[4];

        $this->assertSame('Brace { not json', $brace->message);
        $this->assertSame([], $brace->context);
        $this->assertSame('2026-09-20T08:00:04+00:00', $brace->occurredAt->format(DATE_ATOM));
    }

    public function test_times_without_an_offset_are_read_in_the_app_timezone(): void
    {
        $started = $this->parse(self::SAMPLE, 'Asia/Kolkata')[0];

        $this->assertSame('2026-09-20T02:30:00+00:00', $started->occurredAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));
    }

    public function test_identical_entries_share_a_fingerprint_and_different_ones_do_not(): void
    {
        $entries = $this->parse("[2026-09-20 08:00:00] production.ERROR: Same\n[2026-09-20 08:00:00] production.ERROR: Same\n[2026-09-20 08:00:00] production.ERROR: Other\n");

        $this->assertSame($entries[0]->fingerprint, $entries[1]->fingerprint);
        $this->assertNotSame($entries[0]->fingerprint, $entries[2]->fingerprint);
    }

    public function test_an_unreadable_file_throws(): void
    {
        $this->expectException(RuntimeException::class);

        iterator_to_array((new LaravelLogParser(new DateTimeZone('UTC')))->entries('/nonexistent/laravel.log'));
    }
}
