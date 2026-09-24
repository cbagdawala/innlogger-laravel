<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Laravel\Console;

use Cbagdawala\InnLogger\Client;
use Cbagdawala\InnLogger\SendResult;
use Cbagdawala\InnLogger\Severity;
use Illuminate\Console\Command;
use Throwable;

final class TestCommand extends Command
{
    use DescribesConfiguration;

    /** @var string */
    protected $signature = 'innlogger:test
        {--level=2 : Severity of the test event (1-7); the threshold is bypassed}
        {--message=InnLogger test event : Message of the test event}';

    /** @var string */
    protected $description = 'Send a test event to InnLogger and report configuration, reachability and authentication';

    public function handle(Client $client): int
    {
        $config = $client->config();

        $this->line('<info>Configuration</info>');
        $problem = $this->describeConfiguration($config);
        if ($problem !== null) {
            $this->error('Configuration invalid: '.$this->explainProblem($problem));

            return self::FAILURE;
        }
        $this->line('  Configuration valid.');

        $level = Severity::fromMixed((string) $this->option('level')) ?? Severity::ERROR;

        try {
            $payload = $client->payloadBuilder()->build(
                $level,
                (string) $this->option('message'),
                ['source' => 'innlogger:test'],
                ['category' => 'innlogger-test'],
            );
            $result = $client->send($payload, true);
        } catch (Throwable $e) {
            $this->error('Could not build or send the test event ('.$e::class.').');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('<info>Result</info>');
        $this->line('  Endpoint:        '.$config->endpoint('logs'));
        $this->line('  Event ID:        '.($result->eventId ?? '-'));
        $this->line('  Reachable:       '.($result->reason === 'transport_error' ? 'no ('.($result->error ?? 'connection failed').')' : 'yes'));
        $this->line('  Authentication:  '.$this->authentication($result));
        $this->line('  Response status: '.($result->httpStatus !== null ? (string) $result->httpStatus : '-'));
        $this->line('  Attempts:        '.$result->attempts);

        if ($result->successful()) {
            $this->line('  Log ID:          '.($result->logId ?? '-'));
            $this->info($result->duplicate() ? 'Event accepted (duplicate of an existing event).' : 'Event accepted.');

            return self::SUCCESS;
        }

        $this->error('Event not accepted: '.(string) $result->reason.'.');

        return self::FAILURE;
    }

    private function authentication(SendResult $result): string
    {
        return match (true) {
            $result->successful() => 'ok',
            $result->httpStatus === 401 => 'rejected (invalid API key, secret, timestamp or nonce)',
            $result->httpStatus === 403 => 'rejected (project or credential disabled)',
            $result->httpStatus !== null => 'ok',
            default => 'unknown',
        };
    }
}
