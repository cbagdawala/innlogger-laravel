<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Laravel\Console;

use Cbagdawala\InnLogger\Client;
use Illuminate\Console\Command;
use Throwable;

final class StatusCommand extends Command
{
    use DescribesConfiguration;

    /** @var string */
    protected $signature = 'innlogger:status {--offline : Only show the configuration, do not contact InnLogger}';

    /** @var string */
    protected $description = 'Show the InnLogger configuration and check connectivity with a heartbeat';

    public function handle(Client $client): int
    {
        $config = $client->config();

        $this->line('<info>InnLogger status</info>');
        $problem = $this->describeConfiguration($config);
        $this->line('  Auto exceptions: '.(filter_var(config('innlogger.auto_exception'), FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'no'));
        $this->line('  Transport:       '.(string) config('innlogger.transport', 'laravel'));

        if ($problem !== null) {
            $this->error('Configuration invalid: '.$this->explainProblem($problem));

            return self::FAILURE;
        }

        if ($this->option('offline')) {
            $this->info('Configuration valid.');

            return self::SUCCESS;
        }

        if (! $config->enabled) {
            $this->warn('Configuration valid, but InnLogger is disabled (INNLOGGER_ENABLED=false); heartbeat not sent.');

            return self::SUCCESS;
        }

        try {
            $result = $client->heartbeat([], true);
        } catch (Throwable $e) {
            $this->error('Heartbeat failed ('.$e::class.').');

            return self::FAILURE;
        }

        if ($result->successful()) {
            $this->info('Heartbeat accepted (HTTP '.$result->httpStatus.'). InnLogger is reachable and the credentials are valid.');

            return self::SUCCESS;
        }

        $this->error('Heartbeat failed: '.(string) $result->reason
            .($result->httpStatus !== null ? ' (HTTP '.$result->httpStatus.')' : '')
            .($result->error !== null ? ' ['.$result->error.']' : ''));

        return self::FAILURE;
    }
}
