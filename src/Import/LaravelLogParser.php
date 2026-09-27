<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Import;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Streams entries out of a Laravel log file (Monolog's LineFormatter, as Laravel 8-13 write it):
 *
 *   [2026-09-27 10:15:00] production.ERROR: Payment failed {"order_id":7}
 *   [2026-09-27 10:16:00] production.ERROR: Boom {"userId":3,"exception":"[object] (RuntimeException(code: 0): Boom at /app/X.php:12)
 *   [stacktrace]
 *   #0 {main}
 *   "}
 *
 * Every line up to the next header belongs to the entry (stack traces, multi-line messages).
 * Lines before the first header are ignored. Timestamps without an offset are read in $timezone
 * (Laravel writes them in the app timezone). The file is read line by line, so size is no issue.
 */
final class LaravelLogParser
{
    private const HEADER = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)\] (\S+?)\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY): ?(.*)$/';

    private const EXCEPTION_KEY = '"exception":"[object] (';

    private const EXCEPTION_HEAD = '/^\[object\] \((.+?)\(code: [^)]*\): (.*) at (.+):(\d+)\)$/s';

    /** Upper bound on JSON decode attempts when looking for a trailing context object. */
    private const MAX_CONTEXT_PROBES = 20;

    public function __construct(private readonly DateTimeZone $timezone)
    {
    }

    /**
     * @return iterable<LogEntry>
     */
    public function entries(string $path): iterable
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        try {
            $header = null;
            $body = [];
            $lineNumber = 0;
            $headerLine = 0;

            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $line = rtrim($line, "\r\n");

                if (preg_match(self::HEADER, $line, $match) === 1) {
                    if ($header !== null) {
                        $entry = $this->entry($header, $body, $headerLine);
                        if ($entry !== null) {
                            yield $entry;
                        }
                    }
                    $header = $match;
                    $body = [];
                    $headerLine = $lineNumber;
                } elseif ($header !== null) {
                    $body[] = $line;
                }
            }

            if ($header !== null) {
                $entry = $this->entry($header, $body, $headerLine);
                if ($entry !== null) {
                    yield $entry;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, string>  $header  HEADER match
     * @param  list<string>  $body  continuation lines
     */
    private function entry(array $header, array $body, int $lineNumber): ?LogEntry
    {
        try {
            $occurredAt = new DateTimeImmutable($header[1], $this->timezone);
        } catch (Throwable) {
            return null;
        }

        $raw = rtrim(implode("\n", [$header[4], ...$body]));
        [$message, $context, $exception] = $this->split($raw);

        return new LogEntry(
            $occurredAt,
            $header[2],
            $header[3],
            $message !== '' ? $message : '(empty message)',
            $context,
            $exception,
            sha1($header[1]."\n".$header[2]."\n".$header[3]."\n".$raw),
            $lineNumber,
        );
    }

    /**
     * Separates "message {context} [extra]" into its parts.
     *
     * @return array{0: string, 1: array<mixed>, 2: array{class: string, message: string, file: ?string, line: ?int, trace: ?string}|null}
     */
    private function split(string $text): array
    {
        // Empty "extra" (and, on Laravel 8's formatter, empty context) render as " []".
        foreach ([1, 2] as $ignored) {
            if (str_ends_with($text, ' []')) {
                $text = substr($text, 0, -3);
            }
        }

        $exceptionAt = strpos($text, self::EXCEPTION_KEY);
        if ($exceptionAt !== false) {
            return $this->splitException($text, $exceptionAt);
        }

        // A trailing JSON object is the context; probe " {" positions from the right.
        $offset = strlen($text);
        for ($probe = 0; $probe < self::MAX_CONTEXT_PROBES; $probe++) {
            $at = strrpos($text, ' {', $offset - strlen($text) - 1);
            if ($at === false) {
                break;
            }

            $decoded = json_decode(substr($text, $at + 1), true);
            if (is_array($decoded)) {
                return [rtrim(substr($text, 0, $at)), $decoded, null];
            }

            if ($at === 0) {
                break;
            }
            $offset = $at;
        }

        return [trim($text), [], null];
    }

    /**
     * The exception string inside the context is not valid JSON once Laravel writes its line
     * breaks inline, so it is cut out by position and unescaped by hand.
     *
     * @return array{0: string, 1: array<mixed>, 2: array{class: string, message: string, file: ?string, line: ?int, trace: ?string}}
     */
    private function splitException(string $text, int $exceptionAt): array
    {
        $braceAt = strrpos(substr($text, 0, $exceptionAt), '{');
        $braceAt = $braceAt === false ? $exceptionAt : $braceAt;

        $message = rtrim(substr($text, 0, $braceAt));

        // Context keys before "exception" (Laravel adds userId), e.g. {"userId":3,
        $context = [];
        $before = trim(substr($text, $braceAt + 1, $exceptionAt - $braceAt - 1));
        if ($before !== '') {
            $decoded = json_decode('{'.rtrim($before, ',').'}', true);
            $context = is_array($decoded) ? $decoded : [];
        }

        $value = substr($text, $exceptionAt + strlen('"exception":"'));
        $value = rtrim($value);
        if (str_ends_with($value, '"}')) {
            $value = substr($value, 0, -2);
        }
        $value = rtrim(self::unescape($value));

        $parts = explode("\n[stacktrace]\n", $value, 2);
        $head = $parts[0];
        $trace = isset($parts[1]) ? rtrim($parts[1]) : null;

        if (preg_match(self::EXCEPTION_HEAD, $head, $match) === 1) {
            $exception = [
                'class' => $match[1],
                'message' => $match[2],
                'file' => $match[3],
                'line' => (int) $match[4],
                'trace' => $trace,
            ];
        } else {
            $exception = ['class' => 'UnknownException', 'message' => $head, 'file' => null, 'line' => null, 'trace' => $trace];
        }

        return [$message, $context, $exception];
    }

    /**
     * Undo the JSON string escaping Monolog applied (\\ \" \/), leaving real line breaks alone.
     */
    private static function unescape(string $value): string
    {
        return strtr($value, ['\\\\' => '\\', '\\"' => '"', '\\/' => '/']);
    }
}
