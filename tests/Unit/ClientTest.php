<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Tests\Unit;

use Cbagdawala\InnLogger\Client;
use Cbagdawala\InnLogger\Config;
use Cbagdawala\InnLogger\InnLoggerException;
use Cbagdawala\InnLogger\PayloadBuilder;
use Cbagdawala\InnLogger\Redactor;
use Cbagdawala\InnLogger\SendResult;
use Cbagdawala\InnLogger\Severity;
use Cbagdawala\InnLogger\Tests\Support\FakeTransport;
use Cbagdawala\InnLogger\Transport\TransportException;
use Cbagdawala\InnLogger\Transport\TransportResponse;
use Cbagdawala\InnLogger\Uuid;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

final class ClientTest extends TestCase
{
    private const SECRET = 'ils_TestSecretValue0123456789abcdefABCDEF0123456';

    /** @var list<int> */
    private array $sleeps = [];

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function client(FakeTransport $transport, array $overrides = [], ?\Psr\Log\LoggerInterface $diagnostics = null): Client
    {
        $config = new Config(array_merge([
            'enabled' => true,
            'url' => 'https://logger.example.com/',
            'api_key' => 'ilv_TestKey0123456789abcdefABCDEF01',
            'api_secret' => self::SECRET,
            'log_level' => 7,
            'timeout' => 2,
            'connect_timeout' => 1,
            'environment' => 'production',
            'application' => 'trusted-nanny',
            'hostname' => 'server01',
            'retries' => 1,
            'retry_delay_ms' => 100,
        ], $overrides));

        return new Client(
            $config,
            $transport,
            null,
            $diagnostics,
            function (int $ms): void {
                $this->sleeps[] = $ms;
            },
        );
    }

    public function test_posts_to_the_logs_endpoint_with_signed_headers(): void
    {
        $transport = new FakeTransport();
        $result = $this->client($transport)->error('Payment failed', ['order_id' => 42]);

        $this->assertTrue($result->successful());
        $this->assertSame(202, $result->httpStatus);
        $this->assertSame('log-uuid-1', $result->logId);
        $this->assertCount(1, $transport->requests);

        $request = $transport->requests[0];
        $this->assertSame('https://logger.example.com/api/v1/logs', $request['url']);
        $this->assertSame(2.0, $request['timeout']);
        $this->assertSame(1.0, $request['connect_timeout']);

        $headers = $request['headers'];
        foreach (['X-InnLogger-Key', 'X-InnLogger-Timestamp', 'X-InnLogger-Nonce', 'X-InnLogger-Signature', 'X-InnLogger-Request-Id'] as $name) {
            $this->assertArrayHasKey($name, $headers);
            $this->assertNotSame('', $headers[$name]);
        }
        $this->assertSame('ilv_TestKey0123456789abcdefABCDEF01', $headers['X-InnLogger-Key']);
        $this->assertMatchesRegularExpression('/^\d{10}$/', $headers['X-InnLogger-Timestamp']);
        $this->assertLessThanOrEqual(5, abs(time() - (int) $headers['X-InnLogger-Timestamp']));

        // Recompute the HMAC independently over the exact bytes that were sent.
        $expected = hash_hmac(
            'sha256',
            $headers['X-InnLogger-Timestamp']."\n".$headers['X-InnLogger-Nonce']."\n".$request['body'],
            self::SECRET,
        );
        $this->assertTrue(hash_equals($expected, $headers['X-InnLogger-Signature']));

        // The secret itself is never transmitted.
        $this->assertStringNotContainsString(self::SECRET, $request['body']);
        $this->assertStringNotContainsString(self::SECRET, implode("\n", $headers));
    }

    public function test_payload_matches_the_event_contract(): void
    {
        $transport = new FakeTransport();
        $this->client($transport)->error('Payment failed', ['category' => 'payment', 'order_id' => 42], [
            'request_id' => 'req_123',
            'user_id' => 123,
            'url' => '/api/payment',
            'http_method' => 'post',
            'http_status' => 500,
        ]);

        $payload = $transport->payload();
        $this->assertTrue(Uuid::isV4($payload['event_id']));
        $this->assertSame(2, $payload['level']);
        $this->assertSame('ERROR', $payload['level_name']);
        $this->assertSame('Payment failed', $payload['message']);
        $this->assertSame('payment', $payload['category']);
        $this->assertSame('production', $payload['environment']);
        $this->assertSame('trusted-nanny', $payload['application']);
        $this->assertSame('server01', $payload['hostname']);
        $this->assertSame('req_123', $payload['request_id']);
        $this->assertSame(123, $payload['user_id']);
        $this->assertSame('/api/payment', $payload['url']);
        $this->assertSame('POST', $payload['http_method']);
        $this->assertSame(500, $payload['http_status']);
        $this->assertSame(['order_id' => 42], $payload['context']);
        $this->assertSame(__FILE__, $payload['file']);
        $this->assertIsInt($payload['line']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $payload['occurred_at']);
        $this->assertStringContainsString('"metadata":{}', $transport->requests[0]['body']);
    }

    public function test_every_severity_method_sends_its_level(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);

        $client->critical('c');
        $client->error('e');
        $client->warning('w');
        $client->notice('n');
        $client->info('i');
        $client->debug('d');
        $client->trace('t');

        $levels = array_map(static fn (array $r): array => [
            json_decode($r['body'], true)['level'],
            json_decode($r['body'], true)['level_name'],
        ], $transport->requests);

        $this->assertSame([
            [1, 'CRITICAL'], [2, 'ERROR'], [3, 'WARNING'], [4, 'NOTICE'], [5, 'INFO'], [6, 'DEBUG'], [7, 'TRACE'],
        ], $levels);
    }

    public function test_threshold_filters_less_severe_events(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport, ['log_level' => 2]);

        $this->assertTrue($client->critical('sent')->successful());
        $this->assertTrue($client->error('sent')->successful());
        $warning = $client->warning('dropped');
        $client->info('dropped');
        $client->trace('dropped');

        $this->assertSame(SendResult::SKIPPED, $warning->status);
        $this->assertSame('below_threshold', $warning->reason);
        $this->assertCount(2, $transport->requests);
    }

    public function test_threshold_zero_sends_nothing(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport, ['log_level' => 0]);

        $result = $client->critical('never');
        $client->exception(new RuntimeException('never'));

        $this->assertSame('below_threshold', $result->reason);
        $this->assertSame([], $transport->requests);
    }

    public function test_disabled_mode_makes_no_requests(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport, ['enabled' => false]);

        $this->assertSame('disabled', $client->critical('x')->reason);
        $this->assertSame('disabled', $client->heartbeat()->reason);
        $this->assertSame([], $transport->requests);
    }

    public function test_missing_configuration_is_skipped_without_a_request(): void
    {
        $transport = new FakeTransport();
        $result = $this->client($transport, ['api_secret' => ''])->error('x');

        $this->assertSame(SendResult::FAILED, $result->status);
        $this->assertSame('not_configured', $result->reason);
        $this->assertSame([], $transport->requests);
    }

    public function test_plain_http_is_refused_unless_explicitly_allowed(): void
    {
        $transport = new FakeTransport();

        $refused = $this->client($transport, ['url' => 'http://logger.test'])->error('x');
        $this->assertSame('insecure_url', $refused->reason);
        $this->assertSame([], $transport->requests);

        $allowed = $this->client($transport, ['url' => 'http://logger.test', 'allow_insecure' => true])->error('x');
        $this->assertTrue($allowed->successful());
        $this->assertSame('http://logger.test/api/v1/logs', $transport->requests[0]['url']);
    }

    public function test_timeout_is_swallowed_and_retried_with_the_same_event_id(): void
    {
        $transport = new FakeTransport(
            new TransportException('cURL error 28: Operation timed out'),
            new TransportResponse(202, '{"success":true,"data":{"log_id":"abc"}}'),
        );

        $result = $this->client($transport)->error('slow');

        $this->assertTrue($result->successful());
        $this->assertSame(2, $result->attempts);
        $this->assertCount(2, $transport->requests);
        $this->assertSame([100], $this->sleeps);

        $first = $transport->payload(0);
        $second = $transport->payload(1);
        $this->assertSame($first['event_id'], $second['event_id']);
        $this->assertSame($result->eventId, $first['event_id']);
        $this->assertSame($transport->requests[0]['body'], $transport->requests[1]['body']);
        // Each attempt is a new request: fresh nonce and a valid signature.
        $this->assertNotSame($transport->requests[0]['headers']['X-InnLogger-Nonce'], $transport->requests[1]['headers']['X-InnLogger-Nonce']);
        foreach ($transport->requests as $request) {
            $h = $request['headers'];
            $this->assertSame(
                hash_hmac('sha256', $h['X-InnLogger-Timestamp']."\n".$h['X-InnLogger-Nonce']."\n".$request['body'], self::SECRET),
                $h['X-InnLogger-Signature'],
            );
        }
    }

    public function test_persistent_transport_failure_never_throws(): void
    {
        $transport = new FakeTransport(new RuntimeException('connection refused'));

        $result = $this->client($transport, ['retries' => 2])->critical('down');

        $this->assertSame(SendResult::FAILED, $result->status);
        $this->assertSame('transport_error', $result->reason);
        $this->assertSame(3, $result->attempts);
        $this->assertCount(3, $transport->requests);
        $this->assertSame([100, 200], $this->sleeps);
    }

    public function test_client_errors_are_not_retried(): void
    {
        $transport = new FakeTransport(new TransportResponse(401, '{"success":false,"message":"Invalid credentials"}'));

        $result = $this->client($transport, ['retries' => 3])->error('x');

        $this->assertSame('invalid_credentials', $result->reason);
        $this->assertSame(401, $result->httpStatus);
        $this->assertCount(1, $transport->requests);
    }

    public function test_503_is_retried(): void
    {
        $transport = new FakeTransport(new TransportResponse(503), new TransportResponse(202, '{}'));

        $this->assertTrue($this->client($transport)->error('x')->successful());
        $this->assertCount(2, $transport->requests);
    }

    public function test_429_pauses_sending_without_retrying(): void
    {
        $transport = new FakeTransport(new TransportResponse(429, '{"success":false,"message":"Rate limit exceeded","retry_after":30}'));
        $client = $this->client($transport, ['retries' => 3]);

        $first = $client->error('x');
        $second = $client->error('y');

        $this->assertSame('rate_limited', $first->reason);
        $this->assertSame(429, $first->httpStatus);
        $this->assertSame(SendResult::SKIPPED, $second->status);
        $this->assertSame('rate_limited', $second->reason);
        $this->assertCount(1, $transport->requests);
    }

    public function test_duplicate_event_response_is_a_success(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, '{"success":true,"data":{"log_id":"existing"}}'));

        $result = $this->client($transport)->error('x', [], ['event_id' => '1b4e28ba-2fa1-41d2-883f-0016d3cca427']);

        $this->assertTrue($result->successful());
        $this->assertTrue($result->duplicate());
        $this->assertSame('existing', $result->logId);
        $this->assertSame('1b4e28ba-2fa1-41d2-883f-0016d3cca427', $transport->payload()['event_id']);
    }

    public function test_event_ids_are_unique_uuid_v4(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);

        $ids = [];
        for ($i = 0; $i < 20; $i++) {
            $ids[] = $client->info('x')->eventId;
        }

        $this->assertCount(20, array_unique($ids));
        foreach ($ids as $id) {
            $this->assertTrue(Uuid::isV4((string) $id), (string) $id);
        }
    }

    public function test_fail_silent_false_throws_innlogger_exception(): void
    {
        $transport = new FakeTransport(new TransportResponse(401));

        $this->expectException(InnLoggerException::class);
        $this->expectExceptionMessage('invalid_credentials');

        $this->client($transport, ['fail_silent' => false])->error('x');
    }

    public function test_exception_normalization(): void
    {
        $transport = new FakeTransport();
        $previous = new \LogicException('root cause');
        $exception = new RuntimeException('Gateway timeout', 7, $previous);
        $line = __LINE__ - 1;

        $this->client($transport)->exception($exception, ['order' => 5]);

        $payload = $transport->payload();
        $this->assertSame(2, $payload['level']);
        $this->assertSame('Gateway timeout', $payload['message']);
        $this->assertSame('exception', $payload['category']);
        $this->assertSame(RuntimeException::class, $payload['exception']['class']);
        $this->assertSame('Gateway timeout', $payload['exception']['message']);
        $this->assertSame(__FILE__, $payload['exception']['file']);
        $this->assertSame($line, $payload['exception']['line']);
        $this->assertSame(__FILE__, $payload['file']);
        $this->assertSame($line, $payload['line']);
        $this->assertStringContainsString('#0', $payload['exception']['trace']);
        $this->assertSame(\LogicException::class, $payload['metadata']['previous_exceptions'][0]['class']);
        $this->assertSame(['order' => 5], $payload['context']);
    }

    public function test_exception_trace_is_truncated_to_64_kb(): void
    {
        $exception = $this->deepException(1500);
        $this->assertGreaterThan(65536, strlen($exception->getTraceAsString()));

        $transport = new FakeTransport();
        $this->client($transport)->exception($exception);

        $trace = $transport->payload()['exception']['trace'];
        $this->assertLessThanOrEqual(65536, strlen($trace));
        $this->assertStringEndsWith(PayloadBuilder::TRUNCATED_SUFFIX, $trace);
    }

    public function test_the_same_exception_object_is_only_reported_once(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $exception = new RuntimeException('once');

        $this->assertTrue($client->exception($exception)->successful());
        $this->assertSame('duplicate_exception', $client->error('again', ['exception' => $exception])->reason);
        $this->assertCount(1, $transport->requests);
    }

    public function test_context_is_redacted_before_sending(): void
    {
        $transport = new FakeTransport();
        $this->client($transport, ['redact_fields' => ['iban']])->error('Login failed for token '.self::SECRET, [
            'email' => 'a@example.com',
            'Password' => 'hunter2',
            'payment' => ['IBAN' => 'DE00', 'card_number' => '4111'],
            'headers' => ['authorization' => 'Bearer abc.def'],
        ], ['url' => '/login?password=hunter2&next=/home']);

        $body = $transport->requests[0]['body'];
        $payload = $transport->payload();
        $this->assertStringNotContainsString('hunter2', $body);
        $this->assertStringNotContainsString('DE00', $body);
        $this->assertStringNotContainsString('4111', $body);
        $this->assertStringNotContainsString(self::SECRET, $body);
        $this->assertSame(Redactor::REDACTED, $payload['context']['Password']);
        $this->assertSame(Redactor::REDACTED, $payload['context']['payment']['IBAN']);
        $this->assertSame('a@example.com', $payload['context']['email']);
        $this->assertSame('/login?password=[REDACTED]&next=/home', $payload['url']);
    }

    public function test_oversized_context_is_replaced_by_a_marker(): void
    {
        $transport = new FakeTransport();
        $this->client($transport)->error('big', ['blob' => str_repeat('x', 70000)]);

        $context = $transport->payload()['context'];
        $this->assertTrue($context['_truncated']);
        $this->assertSame(['blob'], $context['_keys']);
        $this->assertLessThan(256 * 1024, strlen($transport->requests[0]['body']));
    }

    public function test_logging_from_inside_a_send_does_not_recurse(): void
    {
        $client = null;
        $inner = null;
        $transport = new FakeTransport(function () use (&$client, &$inner): TransportResponse {
            $inner = $client->error('logged while sending');

            return new TransportResponse(202, '{}');
        });
        $client = $this->client($transport);

        $this->assertTrue($client->error('outer')->successful());
        $this->assertSame('recursion', $inner->reason);
        $this->assertCount(1, $transport->requests);
    }

    public function test_diagnostics_never_contain_secrets_and_do_not_recurse(): void
    {
        $transport = new FakeTransport(new TransportResponse(500));
        $client = null;
        $logger = new class () extends AbstractLogger {
            /** @var list<array{string, array<mixed>}> */
            public array $records = [];
            public ?\Closure $onLog = null;

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
                if ($this->onLog) {
                    ($this->onLog)();
                }
            }
        };
        $client = $this->client($transport, ['retries' => 0], $logger);
        $logger->onLog = static function () use (&$client): void {
            $client->error('from diagnostics');
        };

        $result = $client->error('x', ['password' => 'p']);

        $this->assertSame('server_error', $result->reason);
        $this->assertCount(1, $logger->records);
        $this->assertCount(1, $transport->requests);
        $encoded = json_encode($logger->records);
        $this->assertStringNotContainsString(self::SECRET, (string) $encoded);
        $this->assertStringNotContainsString('X-InnLogger', (string) $encoded);
    }

    public function test_heartbeat(): void
    {
        $transport = new FakeTransport(new TransportResponse(204));
        $client = $this->client($transport, ['application_version' => '1.4.2', 'log_level' => 0]);

        $result = $client->heartbeat();

        $this->assertTrue($result->successful());
        $this->assertSame('https://logger.example.com/api/v1/heartbeat', $transport->requests[0]['url']);
        $this->assertSame(['environment' => 'production', 'hostname' => 'server01', 'application_version' => '1.4.2'], $transport->payload());
        $h = $transport->requests[0]['headers'];
        $this->assertSame(
            hash_hmac('sha256', $h['X-InnLogger-Timestamp']."\n".$h['X-InnLogger-Nonce']."\n".$transport->requests[0]['body'], self::SECRET),
            $h['X-InnLogger-Signature'],
        );
    }

    public function test_invalid_level_is_ignored(): void
    {
        $transport = new FakeTransport();

        $this->assertSame('invalid_level', $this->client($transport)->log(0, 'x')->reason);
        $this->assertSame('invalid_level', $this->client($transport)->log('bogus', 'x')->reason);
        $this->assertTrue($this->client($transport)->log('warning', 'x')->successful());
        $this->assertSame(Severity::WARNING, $transport->payload()['level']);
    }

    private function deepException(int $depth): RuntimeException
    {
        if ($depth === 0) {
            return new RuntimeException('deep');
        }

        return $this->deepException($depth - 1);
    }
}
