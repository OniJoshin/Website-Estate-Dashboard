<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

final class MonitoringThresholds
{
    private readonly int $httpTimeoutSeconds;

    private readonly int $slowHttpMilliseconds;

    private readonly int $httpFailureDebounce;

    private readonly int $httpRecoveryDebounce;

    private readonly int $slowHttpDebounce;

    private readonly int $httpMaxRedirects;

    private readonly int $dnsFailureDebounce;

    private readonly int $dnsRecoveryDebounce;

    private readonly int $serverFailureDebounce;

    private readonly int $serverRecoveryDebounce;

    private readonly int $sslWarningDays;

    private readonly int $sslCriticalDays;

    private readonly int $tlsTimeoutSeconds;

    private readonly int $diskWarningPercent;

    private readonly int $diskCriticalPercent;

    private readonly int $retentionDays;

    public function __construct()
    {
        $this->httpTimeoutSeconds = Config::integer('estate.http.timeout_seconds');
        $this->slowHttpMilliseconds = Config::integer('estate.http.slow_ms');
        $this->httpFailureDebounce = Config::integer('estate.http.failure_debounce');
        $this->httpRecoveryDebounce = Config::integer('estate.http.recovery_debounce');
        $this->slowHttpDebounce = Config::integer('estate.http.slow_debounce');
        $this->httpMaxRedirects = Config::integer('estate.http.max_redirects');
        $this->dnsFailureDebounce = Config::integer('estate.dns.failure_debounce');
        $this->dnsRecoveryDebounce = Config::integer('estate.dns.recovery_debounce');
        $this->serverFailureDebounce = Config::integer('estate.server.failure_debounce');
        $this->serverRecoveryDebounce = Config::integer('estate.server.recovery_debounce');
        $this->sslWarningDays = Config::integer('estate.tls.warning_days');
        $this->sslCriticalDays = Config::integer('estate.tls.critical_days');
        $this->tlsTimeoutSeconds = Config::integer('estate.tls.timeout_seconds');
        $this->diskWarningPercent = Config::integer('estate.disk.warning_percent');
        $this->diskCriticalPercent = Config::integer('estate.disk.critical_percent');
        $this->retentionDays = Config::integer('estate.retention.check_days');

        $this->validate();
    }

    public function httpTimeoutSeconds(): int
    {
        return $this->httpTimeoutSeconds;
    }

    public function slowHttpMilliseconds(): int
    {
        return $this->slowHttpMilliseconds;
    }

    public function httpFailureDebounce(): int
    {
        return $this->httpFailureDebounce;
    }

    public function httpRecoveryDebounce(): int
    {
        return $this->httpRecoveryDebounce;
    }

    public function slowHttpDebounce(): int
    {
        return $this->slowHttpDebounce;
    }

    public function httpMaxRedirects(): int
    {
        return $this->httpMaxRedirects;
    }

    public function dnsFailureDebounce(): int
    {
        return $this->dnsFailureDebounce;
    }

    public function dnsRecoveryDebounce(): int
    {
        return $this->dnsRecoveryDebounce;
    }

    public function serverFailureDebounce(): int
    {
        return $this->serverFailureDebounce;
    }

    public function serverRecoveryDebounce(): int
    {
        return $this->serverRecoveryDebounce;
    }

    public function sslWarningDays(): int
    {
        return $this->sslWarningDays;
    }

    public function sslCriticalDays(): int
    {
        return $this->sslCriticalDays;
    }

    public function tlsTimeoutSeconds(): int
    {
        return $this->tlsTimeoutSeconds;
    }

    public function diskWarningPercent(): int
    {
        return $this->diskWarningPercent;
    }

    public function diskCriticalPercent(): int
    {
        return $this->diskCriticalPercent;
    }

    public function retentionDays(): int
    {
        return $this->retentionDays;
    }

    private function validate(): void
    {
        $this->validatePositiveValues();
        $this->validateDebounceValues();

        if ($this->sslCriticalDays < 0 || $this->sslCriticalDays >= $this->sslWarningDays) {
            throw new InvalidArgumentException('The TLS critical threshold must be non-negative and lower than the warning threshold.');
        }

        if ($this->diskWarningPercent < 0 || $this->diskCriticalPercent > 100) {
            throw new InvalidArgumentException('Disk thresholds must be percentages between 0 and 100.');
        }

        if ($this->diskWarningPercent >= $this->diskCriticalPercent) {
            throw new InvalidArgumentException('The disk warning threshold must be lower than the critical threshold.');
        }
    }

    private function validatePositiveValues(): void
    {
        $values = [
            'HTTP timeout' => $this->httpTimeoutSeconds,
            'HTTP slow response threshold' => $this->slowHttpMilliseconds,
            'HTTP maximum redirects' => $this->httpMaxRedirects,
            'TLS timeout' => $this->tlsTimeoutSeconds,
            'check retention' => $this->retentionDays,
            'server check interval' => Config::integer('estate.schedule.server_minutes'),
            'HTTP check interval' => Config::integer('estate.schedule.http_minutes'),
            'DNS check interval' => Config::integer('estate.schedule.dns_minutes'),
            'TLS check interval' => Config::integer('estate.schedule.tls_minutes'),
        ];

        foreach ($values as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("{$name} must be positive.");
            }
        }
    }

    private function validateDebounceValues(): void
    {
        $values = [
            'HTTP failure debounce' => $this->httpFailureDebounce,
            'HTTP recovery debounce' => $this->httpRecoveryDebounce,
            'HTTP slow response debounce' => $this->slowHttpDebounce,
            'DNS failure debounce' => $this->dnsFailureDebounce,
            'DNS recovery debounce' => $this->dnsRecoveryDebounce,
            'server failure debounce' => $this->serverFailureDebounce,
            'server recovery debounce' => $this->serverRecoveryDebounce,
        ];

        foreach ($values as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("{$name} must be at least one.");
            }
        }
    }
}
