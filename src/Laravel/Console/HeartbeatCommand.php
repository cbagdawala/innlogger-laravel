<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Laravel\Console;

use Cbagdawala\InnLogger\Client;
use Illuminate\Console\Command;
use Throwable;

final class HeartbeatCommand extends Command
{
    /** @var string */
    protected $signature = 'innlogger:heartbeat';

    /** @var string */
    protected $description = 'Send a heartbeat to InnLogger (schedule it every few minutes)';

    public function handle(Client $client): int
    {
        try {
            $result = $client->heartbeat([], true);
        } catch (Throwable $e) {
            $this->error('Heartbeat failed ('.$e::class.').');

            return self::FAILURE;
        }

        if ($result->successful()) {
            $this->info('Heartbeat sent (HTTP '.$result->httpStatus.').');

            return self::SUCCESS;
        }

        if ($result->wasSkipped()) {
            $this->line('Heartbeat skipped: '.(string) $result->reason.'.');

            return self::SUCCESS;
        }

        $this->error('Heartbeat failed: '.(string) $result->reason
            .($result->httpStatus !== null ? ' (HTTP '.$result->httpStatus.')' : ''));

        return self::FAILURE;
    }
}
