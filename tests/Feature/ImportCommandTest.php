<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Tests\Feature;

use Cbagdawala\InnLogger\Client;
use Cbagdawala\InnLogger\Config;
use Cbagdawala\InnLogger\Laravel\Console\ImportCommand;
use Cbagdawala\InnLogger\Tests\Unit\LaravelLogParserTest;
use Cbagdawala\InnLogger\Transport\TransportInterface;
use Cbagdawala\InnLogger\Uuid;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

final class ImportCommandTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = sys_get_temp_dir().'/innlogger-import-'.bin2hex(random_bytes(4)).'.log';
        file_put_contents($this->file, LaravelLogParserTest::SAMPLE);
        ImportCommand::$sleeper = static function (int $milliseconds): void {
        };
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        ImportCommand::$sleeper = null;

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function import(array $options = []): int
    {
        return Artisan::call('innlogger:import', ['paths' => [$this->file], ...$options]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sentPayloads(): array
    {
        return array_map(fn (array $pair): array => $this->payload($pair[0]), Http::recorded()->all());
    }

    public function test_it_imports_entries_at_their_original_time_without_alerts(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['log_id' => 'L']], 202)]);

        $this->assertSame(0, $this->import());

        $payloads = $this->sentPayloads();
        $this->assertCount(6, $payloads);
        Http::assertSent(function (Request $request): bool {
            $this->assertValidSignature($request);

            return true;
        });

        [$started, $payment, $multi, $division] = $payloads;

        $this->assertTrue($started['imported']);
        $this->assertSame('2026-09-20T08:00:00Z', $started['occurred_at']);
        $this->assertSame(5, $started['level']);
        $this->assertSame('production', $started['environment']);
        $this->assertArrayNotHasKey('file', $started);
        $this->assertSame(basename($this->file), $started['metadata']['imported_from']);
        $this->assertSame(2, $started['metadata']['imported_line']);
        $this->assertTrue(Uuid::isV4($started['event_id']));

        $this->assertSame('payment', $payment['category']);
        $this->assertSame(['order_id' => 7], $payment['context']);

        $this->assertSame('local', $multi['environment']);

        $this->assertSame('exception', $division['category']);
        $this->assertSame(3, $division['user_id']);
        $this->assertSame('DivisionByZeroError', $division['exception']['class']);
        $this->assertSame('/var/www/app/Http/Controllers/HomeController.php', $division['file']);
        $this->assertSame(12, $division['line']);
        $this->assertStringContainsString('HomeController->index()', $division['exception']['trace']);

        $this->assertStringContainsString('Done: 6 imported, 0 already imported, 0 failed.', Artisan::output());
    }

    public function test_event_ids_are_the_same_on_every_run(): void
    {
        // First run stores (202); the portal answers 200 "duplicate" to the second.
        $calls = 0;
        Http::fake(static function () use (&$calls) {
            return Http::response([], ++$calls <= 6 ? 202 : 200);
        });

        $this->import();
        $this->import();
        $ids = array_column($this->sentPayloads(), 'event_id');
        [$first, $second] = [array_slice($ids, 0, 6), array_slice($ids, 6)];

        $this->assertCount(6, array_unique($first));
        $this->assertSame($first, $second);
        $this->assertStringContainsString('0 imported, 6 already imported', Artisan::output());
    }

    public function test_identical_entries_stay_distinct_even_when_not_adjacent(): void
    {
        file_put_contents($this->file, implode("\n", [
            '[2026-09-20 08:00:00] production.ERROR: Same',
            '[2026-09-20 08:00:00] production.ERROR: Same',
            '[2026-09-20 08:00:00] production.INFO: Another request in between',
            '[2026-09-20 08:00:00] production.ERROR: Same',
            '',
        ]));
        $calls = 0;
        Http::fake(static function () use (&$calls) {
            return Http::response([], ++$calls <= 4 ? 202 : 200);
        });

        $this->import();
        $this->import();

        $ids = array_column($this->sentPayloads(), 'event_id');
        $this->assertCount(4, array_unique(array_slice($ids, 0, 4)));
        $this->assertSame(array_slice($ids, 0, 4), array_slice($ids, 4), 'a re-run must reuse the same IDs');
    }

    public function test_level_selects_entries(): void
    {
        Http::fake(['*' => Http::response([], 202)]);

        $this->import(['--level' => 'error']);

        $this->assertSame([2, 2, 1, 2], array_column($this->sentPayloads(), 'level'));
    }

    public function test_since_and_until_select_entries(): void
    {
        Http::fake(['*' => Http::response([], 202)]);

        $this->import(['--since' => '2026-09-20 08:00:03', '--until' => '2026-09-20 08:00:05']);

        $this->assertSame(['Division by zero', 'Brace { not json'], array_column($this->sentPayloads(), 'message'));
    }

    public function test_threshold_defaults_to_the_configured_log_level(): void
    {
        config()->set('innlogger.log_level', 1);
        $this->app->forgetInstance(Config::class);
        $this->app->forgetInstance(Client::class);
        Http::fake(['*' => Http::response([], 202)]);

        $this->import();

        $this->assertSame(['Brace { not json'], array_column($this->sentPayloads(), 'message'));
    }

    public function test_environment_option_overrides_the_entries(): void
    {
        Http::fake(['*' => Http::response([], 202)]);

        $this->import(['--environment' => 'legacy']);

        $this->assertSame(['legacy'], array_values(array_unique(array_column($this->sentPayloads(), 'environment'))));
    }

    public function test_alerts_option_sends_without_the_imported_flag(): void
    {
        Http::fake(['*' => Http::response([], 202)]);

        $this->import(['--alerts' => true]);

        foreach ($this->sentPayloads() as $payload) {
            $this->assertArrayNotHasKey('imported', $payload);
        }
    }

    public function test_dry_run_sends_nothing(): void
    {
        Http::fake();

        $this->assertSame(0, $this->import(['--dry-run' => true, '--level' => 'error']));

        Http::assertNothingSent();
        $this->assertStringContainsString('Dry run: 4 of 6 entries would be imported.', Artisan::output());
    }

    public function test_it_waits_out_a_rate_limit_and_carries_on(): void
    {
        $now = 1_800_000_000;
        $slept = [];
        ImportCommand::$sleeper = static function (int $milliseconds) use (&$now, &$slept): void {
            $slept[] = $milliseconds;
            $now += intdiv($milliseconds, 1000);
        };
        $this->app->instance(Client::class, new Client(
            $this->app->make(Config::class),
            $this->app->make(TransportInterface::class),
            null,
            null,
            static function (int $milliseconds): void {
            },
            static function () use (&$now): int {
                return $now;
            },
        ));
        file_put_contents($this->file, "[2026-09-20 08:00:00] production.ERROR: One\n[2026-09-20 08:00:01] production.ERROR: Two\n");
        Http::fake(['*' => Http::sequence()
            ->push(['success' => false, 'retry_after' => 7], 429)
            ->push([], 202)
            ->push([], 202)]);

        $this->assertSame(0, $this->import());

        $this->assertSame(['One', 'One', 'Two'], array_column($this->sentPayloads(), 'message'));
        $this->assertContains(7000, $slept);
        $this->assertStringContainsString('Done: 2 imported', Artisan::output());
    }

    public function test_it_stops_when_the_credentials_are_rejected(): void
    {
        Http::fake(['*' => Http::response(['success' => false], 401)]);

        $this->assertSame(1, $this->import());

        Http::assertSentCount(1);
        $this->assertStringContainsString('InnLogger refused the import: invalid_credentials (HTTP 401)', Artisan::output());
    }

    public function test_it_fails_without_files(): void
    {
        $this->assertSame(1, Artisan::call('innlogger:import', ['paths' => ['/nonexistent/*.log']]));
        $this->assertStringContainsString('No log files found', Artisan::output());
    }

    public function test_it_refuses_when_innlogger_is_disabled(): void
    {
        config()->set('innlogger.enabled', false);
        $this->app->forgetInstance(Config::class);
        $this->app->forgetInstance(Client::class);
        Http::fake();

        $this->assertSame(1, $this->import());

        Http::assertNothingSent();
        $this->assertStringContainsString('InnLogger is disabled', Artisan::output());
    }
}
