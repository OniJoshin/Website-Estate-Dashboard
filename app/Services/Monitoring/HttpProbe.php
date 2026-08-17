<?php

namespace App\Services\Monitoring;

use App\Data\Monitoring\HttpResult;
use App\Support\MonitoringThresholds;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class HttpProbe
{
    private const int CONNECT_TIMEOUT_SECONDS = 5;

    private const string USER_AGENT = 'WebsiteEstateMonitor/1.0';

    public function __construct(private readonly MonitoringThresholds $thresholds) {}

    public function check(string $domain): HttpResult
    {
        $url = 'https://'.$domain.'/';
        $startedAt = hrtime(true);

        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout($this->thresholds->httpTimeoutSeconds())
                ->withOptions([
                    'cookies' => false,
                    'allow_redirects' => [
                        'max' => $this->thresholds->httpMaxRedirects(),
                        'protocols' => ['https'],
                        'track_redirects' => true,
                    ],
                ])
                ->get($url);
        } catch (ConnectionException|RequestException $exception) {
            $errorType = $this->errorType($exception->getMessage());

            return HttpResult::failure(
                errorType: $errorType,
                errorMessage: $this->errorMessage($errorType),
                responseTimeMs: $this->elapsedMilliseconds($startedAt),
            );
        }

        $responseTimeMs = $this->responseTimeMilliseconds($response, $startedAt);
        $redirectHistory = $this->redirectHistory($response);

        if ($this->containsInsecureRedirect($redirectHistory)) {
            return HttpResult::failure(
                errorType: 'transport_error',
                errorMessage: $this->errorMessage('transport_error'),
                responseTimeMs: $responseTimeMs,
            );
        }

        return HttpResult::response(
            httpStatus: $response->status(),
            responseTimeMs: $responseTimeMs,
            finalUrl: $this->finalUrl($response, $url, $redirectHistory),
            redirectCount: count($redirectHistory),
        );
    }

    private function responseTimeMilliseconds(Response $response, int $startedAt): int
    {
        $totalTime = $response->handlerStats()['total_time'] ?? null;

        if (is_numeric($totalTime)) {
            return max(0, (int) round((float) $totalTime * 1000));
        }

        return $this->elapsedMilliseconds($startedAt);
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    /** @return list<string> */
    private function redirectHistory(Response $response): array
    {
        $history = $response->headers()['X-Guzzle-Redirect-History'] ?? [];

        return array_values(array_filter(
            $history,
            static fn (mixed $url): bool => is_string($url) && $url !== '',
        ));
    }

    /** @param list<string> $redirectHistory */
    private function finalUrl(Response $response, string $originalUrl, array $redirectHistory): string
    {
        if ($redirectHistory !== []) {
            return $redirectHistory[array_key_last($redirectHistory)];
        }

        return (string) ($response->effectiveUri() ?? $originalUrl);
    }

    /** @param list<string> $redirectHistory */
    private function containsInsecureRedirect(array $redirectHistory): bool
    {
        foreach ($redirectHistory as $url) {
            if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
                return true;
            }
        }

        return false;
    }

    private function errorType(string $exceptionMessage): string
    {
        $message = Str::lower($exceptionMessage);

        return match (true) {
            Str::contains($message, ['timed out', 'timeout']) => 'timeout',
            Str::contains($message, ['ssl', 'tls', 'certificate']) => 'tls_failed',
            Str::contains($message, ['too many redirects', 'will not follow more than']) => 'too_many_redirects',
            Str::contains($message, ['resolve host', 'connection refused', 'could not connect', 'failed to connect']) => 'connection_failed',
            default => 'transport_error',
        };
    }

    private function errorMessage(string $errorType): string
    {
        return match ($errorType) {
            'connection_failed' => 'Unable to connect to the domain.',
            'timeout' => 'HTTP request timed out.',
            'tls_failed' => 'TLS negotiation failed.',
            'too_many_redirects' => 'Redirect limit exceeded.',
            default => 'HTTP transport failed.',
        };
    }
}
