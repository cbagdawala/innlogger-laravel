<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Laravel\Console;

use Cbagdawala\InnLogger\Config;
use Cbagdawala\InnLogger\Severity;

/**
 * Prints the configuration without ever printing the API secret.
 *
 * @mixin \Illuminate\Console\Command
 */
trait DescribesConfiguration
{
    protected function describeConfiguration(Config $config): ?string
    {
        $sent = [];
        for ($level = Severity::CRITICAL; $level <= Severity::TRACE; $level++) {
            if (Severity::shouldSend($level, $config->logLevel)) {
                $sent[] = Severity::name($level);
            }
        }

        $this->line('  Enabled:         '.($config->enabled ? 'yes' : 'no'));
        $this->line('  URL:             '.($config->url !== '' ? $config->url : '(missing)'));
        $this->line('  API key:         '.$this->maskKey($config->apiKey));
        $this->line('  API secret:      '.($config->hasApiSecret() ? 'set' : '(missing)'));
        $this->line('  Threshold:       '.$config->logLevel.' '.Severity::name($config->logLevel)
            .' (sends: '.($sent === [] ? 'nothing' : implode(', ', $sent)).')');
        $this->line('  Environment:     '.($config->environment ?? '-'));
        $this->line('  Timeout:         '.$config->timeout.'s (connect '.$config->connectTimeout.'s), retries '.$config->retries);
        $this->line('  Fail silent:     '.($config->failSilent ? 'yes' : 'no'));

        return $config->problem();
    }

    protected function explainProblem(string $problem): string
    {
        return match ($problem) {
            'not_configured' => 'INNLOGGER_URL, INNLOGGER_API_KEY and INNLOGGER_API_SECRET are required',
            'invalid_url' => 'INNLOGGER_URL is not a valid http(s) URL',
            'insecure_url' => 'INNLOGGER_URL must use https:// (set INNLOGGER_ALLOW_INSECURE=true for local development only)',
            default => $problem,
        };
    }

    private function maskKey(string $key): string
    {
        if ($key === '') {
            return '(missing)';
        }

        return strlen($key) <= 8 ? str_repeat('*', strlen($key)) : substr($key, 0, 6).'...'.substr($key, -4);
    }
}
