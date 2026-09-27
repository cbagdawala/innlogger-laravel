<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Laravel\Console;

use Cbagdawala\InnLogger\Client;
use Cbagdawala\InnLogger\Import\LaravelLogParser;
use Cbagdawala\InnLogger\Import\LogEntry;
use Cbagdawala\InnLogger\Import\OccurrenceCounter;
use Cbagdawala\InnLogger\PayloadBuilder;
use Cbagdawala\InnLogger\SendResult;
use Cbagdawala\InnLogger\Severity;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfills existing Laravel log files into InnLogger. Imported events are stored at their
 * original time, flagged "imported" so they never send alerts, and get deterministic
 * event IDs, so running the import again never creates duplicates.
 */
final class ImportCommand extends Command
{
    /** @var string */
    protected $signature = 'innlogger:import
        {paths?* : Log files to import (default: storage/logs/laravel*.log)}
        {--since= : Only entries at or after this date/time, e.g. 2026-09-01}
        {--until= : Only entries before this date/time}
        {--level= : Lowest severity to import: 1-7 or a name like error (default: INNLOGGER_LOG_LEVEL)}
        {--environment= : Send every entry with this environment (default: the one written in each entry)}
        {--rate=50 : Maximum events per second (the portal allows 10,000 per minute per project)}
        {--alerts : Let imported events trigger notification rules (off by default)}
        {--dry-run : Parse and count only; send nothing}';

    /** @var string */
    protected $description = 'Import existing Laravel log files into InnLogger (no alerts, safe to re-run)';

    /** Waits for one rate-limited event before the import stops. */
    private const MAX_RATE_LIMIT_WAITS = 5;

    /** Consecutive failed events before the import stops. */
    private const MAX_CONSECUTIVE_FAILURES = 10;

    /**
     * Sleeps for the given milliseconds. Tests replace it.
     *
     * @internal
     *
     * @var (Closure(int): void)|null
     */
    public static ?Closure $sleeper = null;

    /** Set when a failure means the rest of the import cannot succeed either. */
    private ?string $lastFatal = null;

    private ?string $lastReason = null;

    private ?float $lastSentAt = null;

    public function handle(Client $client): int
    {
        try {
            $options = $this->resolveOptions($client);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $files = $this->files();
        if ($files === []) {
            $this->error('No log files found. Pass the file paths, e.g. php artisan innlogger:import storage/logs/laravel.log');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun && ! $client->config()->enabled) {
            $this->error('InnLogger is disabled (INNLOGGER_ENABLED=false). Enable it, or use --dry-run.');

            return self::FAILURE;
        }
        if (! $dryRun && ($problem = $client->config()->problem()) !== null) {
            $this->error('Configuration problem: '.$problem.'. Run php artisan innlogger:status.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            '%s entries of %s and above%s%s from %d file(s)%s.',
            $dryRun ? 'Counting' : 'Importing',
            Severity::name($options['level']),
            $options['since'] !== null ? ' since '.$options['since']->format('Y-m-d H:i:s T') : '',
            $options['until'] !== null ? ' until '.$options['until']->format('Y-m-d H:i:s T') : '',
            count($files),
            $dryRun ? '' : ($this->option('alerts') ? ', alerts ON' : ', without alerts'),
        ));

        $totals = ['read' => 0, 'selected' => 0, 'sent' => 0, 'duplicate' => 0, 'failed' => 0];
        $parser = new LaravelLogParser($options['timezone']);
        $stopped = null;

        foreach ($files as $file) {
            $counts = ['read' => 0, 'selected' => 0, 'sent' => 0, 'duplicate' => 0, 'failed' => 0];
            $occurrences = new OccurrenceCounter();
            $consecutiveFailures = 0;

            try {
                foreach ($parser->entries($file) as $entry) {
                    $counts['read']++;

                    // Identical entries stay distinct, yet get the same IDs on every run.
                    $occurrence = $occurrences->next($entry);

                    if (! $this->selected($entry, $options)) {
                        continue;
                    }
                    $counts['selected']++;

                    if ($dryRun) {
                        continue;
                    }

                    $outcome = $this->sendEntry($client, $file, $entry, $occurrence, $options);
                    $counts[$outcome]++;
                    $consecutiveFailures = $outcome === 'failed' ? $consecutiveFailures + 1 : 0;

                    if ($outcome === 'failed' && $this->lastFatal !== null) {
                        $stopped = $this->lastFatal;
                        break;
                    }
                    if ($consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                        $stopped = 'Stopped after '.self::MAX_CONSECUTIVE_FAILURES.' failures in a row (last: '.$this->lastReason.').';
                        break;
                    }

                    if (($counts['sent'] + $counts['duplicate']) % 500 === 0) {
                        $this->line(sprintf('  %s: %d sent so far...', basename($file), $counts['sent'] + $counts['duplicate']));
                    }
                }
            } catch (Throwable $e) {
                $stopped = 'Could not read '.$file.': '.$e->getMessage();
            }

            $this->line(sprintf(
                '  %s: %d entries, %d selected%s',
                $file,
                $counts['read'],
                $counts['selected'],
                $dryRun ? '' : sprintf(', %d imported, %d already imported, %d failed', $counts['sent'], $counts['duplicate'], $counts['failed']),
            ));

            foreach ($counts as $key => $value) {
                $totals[$key] += $value;
            }

            if ($stopped !== null) {
                break;
            }
        }

        if ($stopped !== null) {
            $this->error($stopped.' Fix it and run the same command again: entries already imported are skipped.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info(sprintf('Dry run: %d of %d entries would be imported.', $totals['selected'], $totals['read']));

            return self::SUCCESS;
        }

        $summary = sprintf('Done: %d imported, %d already imported, %d failed.', $totals['sent'], $totals['duplicate'], $totals['failed']);
        if ($totals['failed'] > 0) {
            $this->warn($summary.' Run the command again to retry the failed entries.');

            return self::FAILURE;
        }

        $this->info($summary);

        return self::SUCCESS;
    }

    /**
     * @return array{level: int, since: ?DateTimeImmutable, until: ?DateTimeImmutable, timezone: DateTimeZone, environment: ?string, rate: int}
     */
    private function resolveOptions(Client $client): array
    {
        $timezone = new DateTimeZone((string) (config('app.timezone') ?: 'UTC'));

        $levelOption = $this->option('level');
        if ($levelOption !== null && $levelOption !== '') {
            $level = Severity::fromMixed(is_numeric($levelOption) ? (int) $levelOption : (string) $levelOption);
            if ($level === null || $level === Severity::OFF) {
                throw new \InvalidArgumentException('--level must be 1-7 or a severity name such as error or warning.');
            }
        } else {
            $level = $client->config()->logLevel;
            if ($level === Severity::OFF) {
                throw new \InvalidArgumentException('INNLOGGER_LOG_LEVEL is 0 (sends nothing). Pass --level, e.g. --level=error.');
            }
        }

        $rate = (int) $this->option('rate');
        if ($rate < 1) {
            throw new \InvalidArgumentException('--rate must be at least 1.');
        }

        $environment = $this->option('environment');

        return [
            'level' => $level,
            'since' => $this->date('since', $timezone),
            'until' => $this->date('until', $timezone),
            'timezone' => $timezone,
            'environment' => is_string($environment) && $environment !== '' ? $environment : null,
            'rate' => $rate,
        ];
    }

    private function date(string $option, DateTimeZone $timezone): ?DateTimeImmutable
    {
        $value = $this->option($option);
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable((string) $value, $timezone);
        } catch (Throwable) {
            throw new \InvalidArgumentException("--{$option} is not a date: {$value}");
        }
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $paths = (array) $this->argument('paths');
        if ($paths === []) {
            $paths = [storage_path('logs/laravel*.log')];
        }

        $files = [];
        foreach ($paths as $path) {
            $matches = is_file($path) ? [$path] : (glob($path) ?: []);
            sort($matches);
            foreach ($matches as $match) {
                if (is_file($match) && ! in_array($match, $files, true)) {
                    $files[] = $match;
                }
            }
        }

        return $files;
    }

    /**
     * @param  array{level: int, since: ?DateTimeImmutable, until: ?DateTimeImmutable}  $options
     */
    private function selected(LogEntry $entry, array $options): bool
    {
        $severity = Severity::fromMixed($entry->levelName);

        return $severity !== null
            && Severity::shouldSend($severity, $options['level'])
            && ($options['since'] === null || $entry->occurredAt >= $options['since'])
            && ($options['until'] === null || $entry->occurredAt < $options['until']);
    }

    /**
     * @param  array{environment: ?string, rate: int}  $options
     * @return 'sent'|'duplicate'|'failed'
     */
    private function sendEntry(Client $client, string $file, LogEntry $entry, int $occurrence, array $options): string
    {
        $payload = $this->payload($client, $file, $entry, $occurrence, $options['environment']);

        for ($wait = 0; ; $wait++) {
            $this->throttle($options['rate']);
            $result = $client->send($payload, true);

            if ($result->successful()) {
                return $result->duplicate() ? 'duplicate' : 'sent';
            }

            $this->lastReason = self::describe($result);

            if ($result->reason === 'rate_limited' && $wait < self::MAX_RATE_LIMIT_WAITS) {
                $seconds = max(1, $client->pausedFor());
                $this->warn("  Rate limited by InnLogger; waiting {$seconds}s...");
                $this->sleep($seconds * 1000);

                continue;
            }

            if ($result->reason === 'rate_limited') {
                $this->lastFatal = 'Still rate limited after '.self::MAX_RATE_LIMIT_WAITS.' waits; lower --rate.';
            } elseif (in_array($result->reason, ['invalid_credentials', 'forbidden', 'not_configured', 'invalid_url', 'insecure_url'], true)) {
                $this->lastFatal = 'InnLogger refused the import: '.$this->lastReason.'.';
            }

            return 'failed';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Client $client, string $file, LogEntry $entry, int $occurrence, ?string $environment): array
    {
        $config = $client->config();
        $severity = (int) Severity::fromMixed($entry->levelName);
        $context = $entry->context;

        $attributes = [
            'event_id' => self::eventId($entry->fingerprint, $occurrence),
            'occurred_at' => $entry->occurredAt,
            'metadata' => [
                'imported_from' => basename($file),
                'imported_line' => $entry->lineNumber,
                'monolog_level' => $entry->levelName,
            ],
        ];

        if (isset($context['category']) && is_string($context['category'])) {
            $attributes['category'] = $context['category'];
            unset($context['category']);
        }

        if ($entry->exception !== null) {
            $exception = $entry->exception;
            $exception['message'] = PayloadBuilder::truncate($exception['message'], $config->maxMessageBytes);
            if ($exception['trace'] !== null) {
                $exception['trace'] = PayloadBuilder::truncate($exception['trace'], $config->maxTraceBytes);
            }
            $attributes['exception'] = array_filter($exception, static fn ($value): bool => $value !== null);
            $attributes['category'] ??= 'exception';
        }

        // userId is what Laravel's exception handler adds to the context.
        if (isset($context['userId']) && (is_int($context['userId']) || is_string($context['userId']))) {
            $attributes['user_id'] = $context['userId'];
            unset($context['userId']);
        }

        $payload = $client->payloadBuilder()->build($severity, $entry->message, $context, $attributes);

        // The builder fills file/line from its caller (this command) when an entry has none.
        if ($entry->exception === null) {
            unset($payload['file'], $payload['line']);
        } else {
            $payload['file'] = $entry->exception['file'];
            $payload['line'] = $entry->exception['line'];
            $payload = array_filter($payload, static fn ($value): bool => $value !== null);
        }

        $payload['environment'] = mb_substr($environment ?? $entry->environment, 0, 100);

        if (! $this->option('alerts')) {
            $payload['imported'] = true;
        }

        return $payload;
    }

    /**
     * A UUID in version-4 format derived from the entry, so re-imports reuse it.
     */
    public static function eventId(string $fingerprint, int $occurrence): string
    {
        $hash = sha1('innlogger-import:'.$fingerprint.':'.$occurrence);
        $hash[12] = '4';
        $hash[16] = dechex((hexdec($hash[16]) & 0x3) | 0x8);

        return sprintf('%s-%s-%s-%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12));
    }

    private function throttle(int $rate): void
    {
        $interval = 1.0 / $rate;
        $now = microtime(true);

        if ($this->lastSentAt !== null && ($now - $this->lastSentAt) < $interval) {
            $this->sleep((int) ceil(($interval - ($now - $this->lastSentAt)) * 1000));
        }

        $this->lastSentAt = microtime(true);
    }

    private function sleep(int $milliseconds): void
    {
        if (self::$sleeper !== null) {
            (self::$sleeper)($milliseconds);

            return;
        }

        usleep($milliseconds * 1000);
    }

    private static function describe(SendResult $result): string
    {
        return (string) $result->reason.($result->httpStatus !== null ? ' (HTTP '.$result->httpStatus.')' : '');
    }
}
