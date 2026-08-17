<?php

namespace Tests\Unit\Monitoring;

use App\Data\Monitoring\TlsResult;
use App\Services\Monitoring\Contracts\TlsInspector;
use App\Services\Monitoring\NativeTlsInspector;
use App\Support\MonitoringThresholds;
use Carbon\CarbonImmutable;
use Closure;
use stdClass;
use Tests\TestCase;

class NativeTlsInspectorTest extends TestCase
{
    private object $stream;

    private object $certificate;

    /** @var array<string, mixed>|false */
    private array|false $parsedCertificate;

    /** @var list<array{hostname: string, port: int, timeout: int, ssl: array<string, mixed>}> */
    private array $connectionCalls = [];

    private bool $connectionSucceeds = true;

    private ?int $connectionErrorCode = null;

    private ?string $connectionErrorMessage = null;

    private bool $includeCertificate = true;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-01 00:00:00 UTC'));
        $this->stream = new stdClass;
        $this->certificate = (object) ['private_certificate_material' => 'not-for-the-result'];
        $this->parsedCertificate = [
            'validTo_time_t' => CarbonImmutable::now('UTC')->addDays(30)->addHours(12)->getTimestamp(),
        ];
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_contract_is_bound_to_native_inspector(): void
    {
        $this->assertInstanceOf(NativeTlsInspector::class, app(TlsInspector::class));
    }

    public function test_connection_uses_hostname_default_port_timeout_and_explicit_secure_context(): void
    {
        $result = $this->inspector()->inspect('example.invalid');

        $this->assertTrue($result->successful);
        $this->assertCount(1, $this->connectionCalls);
        $call = $this->connectionCalls[0];
        $this->assertSame('example.invalid', $call['hostname']);
        $this->assertSame(443, $call['port']);
        $this->assertSame(10, $call['timeout']);
        $this->assertSame([
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => 'example.invalid',
            'SNI_enabled' => true,
            'capture_peer_cert' => true,
        ], $call['ssl']);
    }

    public function test_configured_timeout_and_explicit_port_are_used(): void
    {
        config()->set('estate.tls.timeout_seconds', 17);

        $this->inspector()->inspect('example.invalid', 8443);

        $this->assertSame(8443, $this->connectionCalls[0]['port']);
        $this->assertSame(17, $this->connectionCalls[0]['timeout']);
    }

    public function test_valid_certificate_is_normalized_without_exposing_certificate_material(): void
    {
        $result = $this->inspector()->inspect('example.invalid');

        $this->assertTrue($result->successful);
        $this->assertTrue($result->sslValid);
        $this->assertInstanceOf(CarbonImmutable::class, $result->expiresAt);
        $this->assertSame('2026-01-31T12:00:00+00:00', $result->expiresAt->toIso8601String());
        $this->assertSame(30, $result->daysRemaining);
        $this->assertNull($result->errorType);
        $this->assertNull($result->errorMessage);
        $this->assertObjectNotHasProperty('certificate', $result);
        $this->assertStringNotContainsString('private_certificate_material', serialize($result));
    }

    public function test_days_remaining_uses_floor_of_full_utc_days(): void
    {
        foreach ([
            '30.x days' => [30, 12, 30],
            '29.x days' => [29, 12, 29],
            '6.x days' => [6, 12, 6],
            'later today' => [0, 12, 0],
        ] as [$days, $hours, $expected]) {
            $this->parsedCertificate = [
                'validTo_time_t' => CarbonImmutable::now('UTC')->addDays($days)->addHours($hours)->getTimestamp(),
            ];

            $this->assertSame($expected, $this->inspector()->inspect('example.invalid')->daysRemaining);
        }
    }

    public function test_failed_connection_is_a_safe_unsuccessful_result_without_insecure_retry(): void
    {
        $this->connectionSucceeds = false;
        $this->connectionErrorCode = 111;
        $this->connectionErrorMessage = 'certificate verify failed TASK12_SUPER_SECRET_TOKEN';

        $result = $this->inspector()->inspect('example.invalid');

        $this->assertFailure($result, 'connection_failed', 'TLS connection failed.');
        $this->assertCount(1, $this->connectionCalls);
        $this->assertTrue($this->connectionCalls[0]['ssl']['verify_peer']);
        $this->assertStringNotContainsString('TASK12_SUPER_SECRET_TOKEN', serialize($result));
    }

    public function test_validation_failure_is_tls_invalid(): void
    {
        $this->connectionSucceeds = false;
        $this->connectionErrorMessage = 'Peer certificate CN mismatch';

        $this->assertFailure(
            $this->inspector()->inspect('example.invalid'),
            'tls_invalid',
            'TLS certificate validation failed.',
        );
    }

    public function test_numeric_timeout_error_is_normalized(): void
    {
        $this->connectionSucceeds = false;
        $this->connectionErrorCode = defined('SOCKET_ETIMEDOUT') ? SOCKET_ETIMEDOUT : 110;
        $this->connectionErrorMessage = 'Connection timed out with secret details';

        $this->assertFailure(
            $this->inspector()->inspect('example.invalid'),
            'timeout',
            'TLS connection timed out.',
        );
    }

    public function test_windows_numeric_timeout_error_is_normalized_without_socket_extension_constants(): void
    {
        $this->connectionSucceeds = false;
        $this->connectionErrorCode = 10_060;

        $this->assertFailure(
            $this->inspector()->inspect('example.invalid'),
            'timeout',
            'TLS connection timed out.',
        );
    }

    public function test_native_warning_is_locally_suppressed_and_not_exposed(): void
    {
        $connector = function (
            string $hostname,
            int $port,
            int $timeout,
            array $ssl,
            ?int &$errorCode,
            ?string &$errorMessage,
        ): false {
            trigger_error('stream_socket_client secret native warning', E_USER_WARNING);

            return false;
        };

        $result = $this->inspector(Closure::fromCallable($connector))->inspect('example.invalid');

        $this->assertFailure($result, 'tls_invalid', 'TLS certificate validation failed.');
        $this->assertStringNotContainsString('native warning', $result->errorMessage);
    }

    public function test_missing_peer_certificate_is_a_parse_failure(): void
    {
        $this->includeCertificate = false;

        $this->assertParseFailure($this->inspector()->inspect('example.invalid'));
    }

    public function test_certificate_parser_failure_is_a_parse_failure(): void
    {
        $this->parsedCertificate = false;

        $this->assertParseFailure($this->inspector()->inspect('example.invalid'));
    }

    public function test_missing_or_malformed_expiry_is_a_parse_failure(): void
    {
        foreach ([[], ['validTo_time_t' => 'not-a-timestamp']] as $parsedCertificate) {
            $this->parsedCertificate = $parsedCertificate;

            $this->assertParseFailure($this->inspector()->inspect('example.invalid'));
        }
    }

    public function test_unrepresentable_integer_expiry_is_a_parse_failure(): void
    {
        $this->parsedCertificate = ['validTo_time_t' => PHP_INT_MAX];

        $this->assertParseFailure($this->inspector()->inspect('example.invalid'));
    }

    public function test_expired_parsed_certificate_is_invalid(): void
    {
        $this->parsedCertificate = [
            'validTo_time_t' => CarbonImmutable::now('UTC')->subSecond()->getTimestamp(),
        ];

        $this->assertFailure(
            $this->inspector()->inspect('example.invalid'),
            'tls_invalid',
            'TLS certificate validation failed.',
        );
    }

    public function test_error_handler_is_restored_after_success_and_failure(): void
    {
        $originalHandler = static fn (): bool => false;
        set_error_handler($originalHandler);

        try {
            $this->inspector()->inspect('example.invalid');
            $this->assertSame($originalHandler, $this->replaceAndReturnPreviousHandler());

            $this->connectionSucceeds = false;
            $this->inspector()->inspect('example.invalid');
            $this->assertSame($originalHandler, $this->replaceAndReturnPreviousHandler());
        } finally {
            restore_error_handler();
        }
    }

    private function inspector(?Closure $connector = null): NativeTlsInspector
    {
        $connector ??= function (
            string $hostname,
            int $port,
            int $timeout,
            array $ssl,
            ?int &$errorCode,
            ?string &$errorMessage,
        ): object|false {
            $this->connectionCalls[] = compact('hostname', 'port', 'timeout', 'ssl');
            $errorCode = $this->connectionErrorCode;
            $errorMessage = $this->connectionErrorMessage;

            return $this->connectionSucceeds ? $this->stream : false;
        };

        $contextReader = function (mixed $stream): array {
            $this->assertSame($this->stream, $stream);

            return ['options' => ['ssl' => $this->includeCertificate
                ? ['peer_certificate' => $this->certificate]
                : []]];
        };

        $certificateParser = function (mixed $certificate): array|false {
            $this->assertSame($this->certificate, $certificate);

            return $this->parsedCertificate;
        };

        return new NativeTlsInspector(
            thresholds: new MonitoringThresholds,
            connector: Closure::fromCallable($connector),
            contextReader: Closure::fromCallable($contextReader),
            certificateParser: Closure::fromCallable($certificateParser),
        );
    }

    private function assertFailure(TlsResult $result, string $errorType, string $errorMessage): void
    {
        $this->assertFalse($result->successful);
        $this->assertFalse($result->sslValid);
        $this->assertNull($result->expiresAt);
        $this->assertNull($result->daysRemaining);
        $this->assertSame($errorType, $result->errorType);
        $this->assertSame($errorMessage, $result->errorMessage);
    }

    private function assertParseFailure(TlsResult $result): void
    {
        $this->assertFailure($result, 'certificate_parse_error', 'TLS certificate could not be inspected.');
    }

    private function replaceAndReturnPreviousHandler(): mixed
    {
        $previous = set_error_handler(static fn (): bool => true);
        restore_error_handler();

        return $previous;
    }
}
