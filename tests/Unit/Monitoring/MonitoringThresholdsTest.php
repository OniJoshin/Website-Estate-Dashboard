<?php

namespace Tests\Unit\Monitoring;

use App\Support\MonitoringThresholds;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MonitoringThresholdsTest extends TestCase
{
    public function test_estate_configuration_has_the_expected_defaults(): void
    {
        $this->assertSame(10, config('estate.http.timeout_seconds'));
        $this->assertSame(2000, config('estate.http.slow_ms'));
        $this->assertSame(2, config('estate.http.failure_debounce'));
        $this->assertSame(2, config('estate.http.recovery_debounce'));
        $this->assertSame(3, config('estate.http.slow_debounce'));
        $this->assertSame(10, config('estate.http.max_redirects'));
        $this->assertIsInt(config('estate.http.max_redirects'));
        $this->assertSame(2, config('estate.dns.failure_debounce'));
        $this->assertSame(2, config('estate.dns.recovery_debounce'));
        $this->assertSame(2, config('estate.server.failure_debounce'));
        $this->assertSame(2, config('estate.server.recovery_debounce'));
        $this->assertSame(30, config('estate.tls.warning_days'));
        $this->assertSame(7, config('estate.tls.critical_days'));
        $this->assertSame(10, config('estate.tls.timeout_seconds'));
        $this->assertIsInt(config('estate.tls.timeout_seconds'));
        $this->assertSame(85, config('estate.disk.warning_percent'));
        $this->assertSame(95, config('estate.disk.critical_percent'));
        $this->assertSame(90, config('estate.retention.check_days'));
        $this->assertSame(5, config('estate.schedule.server_minutes'));
        $this->assertSame(10, config('estate.schedule.http_minutes'));
        $this->assertSame(360, config('estate.schedule.dns_minutes'));
        $this->assertSame(360, config('estate.schedule.tls_minutes'));
        $this->assertSame('03:00', config('estate.schedule.inventory_time'));
        $this->assertSame('Europe/London', config('estate.schedule.inventory_timezone'));
        $this->assertSame(3, config('estate.operations.heartbeat_stale_minutes'));
        $this->assertIsInt(config('estate.operations.heartbeat_stale_minutes'));
    }

    public function test_monitoring_thresholds_return_typed_default_values(): void
    {
        $thresholds = new MonitoringThresholds;

        $values = [
            [$thresholds->httpTimeoutSeconds(), 10],
            [$thresholds->slowHttpMilliseconds(), 2000],
            [$thresholds->httpFailureDebounce(), 2],
            [$thresholds->httpRecoveryDebounce(), 2],
            [$thresholds->slowHttpDebounce(), 3],
            [$thresholds->httpMaxRedirects(), 10],
            [$thresholds->dnsFailureDebounce(), 2],
            [$thresholds->dnsRecoveryDebounce(), 2],
            [$thresholds->serverFailureDebounce(), 2],
            [$thresholds->serverRecoveryDebounce(), 2],
            [$thresholds->sslWarningDays(), 30],
            [$thresholds->sslCriticalDays(), 7],
            [$thresholds->tlsTimeoutSeconds(), 10],
            [$thresholds->diskWarningPercent(), 85],
            [$thresholds->diskCriticalPercent(), 95],
            [$thresholds->retentionDays(), 90],
        ];

        foreach ($values as [$actual, $expected]) {
            $this->assertIsInt($actual);
            $this->assertSame($expected, $actual);
        }
    }

    public function test_monitoring_thresholds_use_config_overrides(): void
    {
        config()->set([
            'estate.http.timeout_seconds' => 11,
            'estate.http.slow_ms' => 2100,
            'estate.http.failure_debounce' => 3,
            'estate.http.recovery_debounce' => 4,
            'estate.http.slow_debounce' => 5,
            'estate.http.max_redirects' => 12,
            'estate.dns.failure_debounce' => 6,
            'estate.dns.recovery_debounce' => 7,
            'estate.server.failure_debounce' => 8,
            'estate.server.recovery_debounce' => 9,
            'estate.tls.warning_days' => 40,
            'estate.tls.critical_days' => 10,
            'estate.tls.timeout_seconds' => 12,
            'estate.disk.warning_percent' => 80,
            'estate.disk.critical_percent' => 90,
            'estate.retention.check_days' => 120,
        ]);

        $thresholds = new MonitoringThresholds;

        $this->assertSame(11, $thresholds->httpTimeoutSeconds());
        $this->assertSame(2100, $thresholds->slowHttpMilliseconds());
        $this->assertSame(3, $thresholds->httpFailureDebounce());
        $this->assertSame(4, $thresholds->httpRecoveryDebounce());
        $this->assertSame(5, $thresholds->slowHttpDebounce());
        $this->assertSame(12, $thresholds->httpMaxRedirects());
        $this->assertSame(6, $thresholds->dnsFailureDebounce());
        $this->assertSame(7, $thresholds->dnsRecoveryDebounce());
        $this->assertSame(8, $thresholds->serverFailureDebounce());
        $this->assertSame(9, $thresholds->serverRecoveryDebounce());
        $this->assertSame(40, $thresholds->sslWarningDays());
        $this->assertSame(10, $thresholds->sslCriticalDays());
        $this->assertSame(12, $thresholds->tlsTimeoutSeconds());
        $this->assertSame(80, $thresholds->diskWarningPercent());
        $this->assertSame(90, $thresholds->diskCriticalPercent());
        $this->assertSame(120, $thresholds->retentionDays());
    }

    #[DataProvider('invalidDebounceProvider')]
    public function test_debounce_values_must_be_at_least_one(string $key): void
    {
        config()->set($key, 0);

        $this->expectException(InvalidArgumentException::class);

        new MonitoringThresholds;
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDebounceProvider(): iterable
    {
        yield 'HTTP failure' => ['estate.http.failure_debounce'];
        yield 'HTTP recovery' => ['estate.http.recovery_debounce'];
        yield 'HTTP slow response' => ['estate.http.slow_debounce'];
        yield 'DNS failure' => ['estate.dns.failure_debounce'];
        yield 'DNS recovery' => ['estate.dns.recovery_debounce'];
        yield 'server failure' => ['estate.server.failure_debounce'];
        yield 'server recovery' => ['estate.server.recovery_debounce'];
    }

    #[DataProvider('nonPositiveValueProvider')]
    public function test_timeout_retention_and_intervals_must_be_positive(string $key): void
    {
        config()->set($key, 0);

        $this->expectException(InvalidArgumentException::class);

        new MonitoringThresholds;
    }

    /** @return iterable<string, array{string}> */
    public static function nonPositiveValueProvider(): iterable
    {
        yield 'HTTP timeout' => ['estate.http.timeout_seconds'];
        yield 'HTTP slow response threshold' => ['estate.http.slow_ms'];
        yield 'HTTP maximum redirects' => ['estate.http.max_redirects'];
        yield 'TLS timeout' => ['estate.tls.timeout_seconds'];
        yield 'retention' => ['estate.retention.check_days'];
        yield 'server interval' => ['estate.schedule.server_minutes'];
        yield 'HTTP interval' => ['estate.schedule.http_minutes'];
        yield 'DNS interval' => ['estate.schedule.dns_minutes'];
        yield 'TLS interval' => ['estate.schedule.tls_minutes'];
    }

    #[DataProvider('invalidTlsThresholdProvider')]
    public function test_ssl_critical_days_must_be_less_than_warning_days(int $warning, int $critical): void
    {
        config()->set([
            'estate.tls.warning_days' => $warning,
            'estate.tls.critical_days' => $critical,
        ]);

        $this->expectException(InvalidArgumentException::class);

        new MonitoringThresholds;
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidTlsThresholdProvider(): iterable
    {
        yield 'equal' => [7, 7];
        yield 'critical greater' => [7, 8];
    }

    #[DataProvider('invalidDiskThresholdProvider')]
    public function test_disk_thresholds_must_be_ordered_percentages(int $warning, int $critical): void
    {
        config()->set([
            'estate.disk.warning_percent' => $warning,
            'estate.disk.critical_percent' => $critical,
        ]);

        $this->expectException(InvalidArgumentException::class);

        new MonitoringThresholds;
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidDiskThresholdProvider(): iterable
    {
        yield 'equal' => [90, 90];
        yield 'warning greater' => [96, 95];
        yield 'warning below zero' => [-1, 95];
        yield 'critical below zero' => [85, -1];
        yield 'critical above one hundred' => [85, 101];
    }
}
