<?php

namespace App\Services\Monitoring;

use App\Data\Monitoring\TlsResult;
use App\Services\Monitoring\Contracts\TlsInspector;
use App\Support\MonitoringThresholds;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;

final class NativeTlsInspector implements TlsInspector
{
    /**
     * @var Closure(string, int, int, array<string, mixed>, ?int&, ?string&): mixed
     */
    private Closure $connector;

    /** @var Closure(mixed): array<string, mixed> */
    private Closure $contextReader;

    /** @var Closure(mixed): (array<string, mixed>|false) */
    private Closure $certificateParser;

    public function __construct(
        private readonly MonitoringThresholds $thresholds,
        ?Closure $connector = null,
        ?Closure $contextReader = null,
        ?Closure $certificateParser = null,
    ) {
        $this->connector = $connector ?? self::nativeConnector(...);
        $this->contextReader = $contextReader ?? stream_context_get_params(...);
        $this->certificateParser = $certificateParser ?? openssl_x509_parse(...);
    }

    public function inspect(string $hostname, int $port = 443): TlsResult
    {
        $sslOptions = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $hostname,
            'SNI_enabled' => true,
            'capture_peer_cert' => true,
        ];
        $errorCode = null;
        $errorMessage = null;
        $stream = null;

        set_error_handler(static fn (): bool => true);

        try {
            $stream = ($this->connector)(
                $hostname,
                $port,
                $this->thresholds->tlsTimeoutSeconds(),
                $sslOptions,
                $errorCode,
                $errorMessage,
            );

            if ($stream === false) {
                return $this->connectionFailure($errorCode);
            }

            $parameters = ($this->contextReader)($stream);
            $certificate = $parameters['options']['ssl']['peer_certificate'] ?? null;

            if ($certificate === null) {
                return self::certificateParseFailure();
            }

            $parsedCertificate = ($this->certificateParser)($certificate);
            $rawExpiry = is_array($parsedCertificate) ? ($parsedCertificate['validTo_time_t'] ?? null) : null;
            $expiryTimestamp = filter_var($rawExpiry, FILTER_VALIDATE_INT);

            if ($expiryTimestamp === false) {
                return self::certificateParseFailure();
            }

            try {
                $expiresAt = CarbonImmutable::createFromTimestampUTC($expiryTimestamp);
            } catch (InvalidFormatException) {
                return self::certificateParseFailure();
            }
            $now = CarbonImmutable::now('UTC');

            if ($expiresAt->lessThanOrEqualTo($now)) {
                return TlsResult::failure('tls_invalid', 'TLS certificate validation failed.');
            }

            $daysRemaining = intdiv($expiresAt->getTimestamp() - $now->getTimestamp(), 86_400);

            return TlsResult::valid($expiresAt, $daysRemaining);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            restore_error_handler();
        }
    }

    /**
     * @param  array<string, mixed>  $sslOptions
     */
    private static function nativeConnector(
        string $hostname,
        int $port,
        int $timeout,
        array $sslOptions,
        ?int &$errorCode,
        ?string &$errorMessage,
    ): mixed {
        $context = stream_context_create(['ssl' => $sslOptions]);
        $nativeErrorCode = 0;
        $nativeErrorMessage = '';
        $stream = stream_socket_client(
            "tls://{$hostname}:{$port}",
            $nativeErrorCode,
            $nativeErrorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        $errorCode = $nativeErrorCode === 0 ? null : $nativeErrorCode;
        $errorMessage = $nativeErrorMessage === '' ? null : $nativeErrorMessage;

        return $stream;
    }

    private function connectionFailure(?int $errorCode): TlsResult
    {
        if ($this->isTimeoutError($errorCode)) {
            return TlsResult::failure('timeout', 'TLS connection timed out.');
        }

        if ($errorCode !== null) {
            return TlsResult::failure('connection_failed', 'TLS connection failed.');
        }

        return TlsResult::failure('tls_invalid', 'TLS certificate validation failed.');
    }

    private function isTimeoutError(?int $errorCode): bool
    {
        return in_array($errorCode, [110, 10_060], true);
    }

    private static function certificateParseFailure(): TlsResult
    {
        return TlsResult::failure('certificate_parse_error', 'TLS certificate could not be inspected.');
    }
}
