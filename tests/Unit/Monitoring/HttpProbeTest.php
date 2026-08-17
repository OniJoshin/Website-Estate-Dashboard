<?php

namespace Tests\Unit\Monitoring;

use App\Data\Monitoring\HttpResult;
use App\Services\Monitoring\HttpProbe;
use App\Support\MonitoringThresholds;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

class HttpProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_request_uses_secure_bounded_read_only_transport_options(): void
    {
        config()->set([
            'estate.http.timeout_seconds' => 17,
            'estate.http.max_redirects' => 7,
        ]);
        $capturedOptions = null;
        Http::fake(function (Request $request, array $options) use (&$capturedOptions) {
            $capturedOptions = $options;

            return Http::response('ignored body', 200);
        });

        $this->probe()->check('example.invalid');

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://example.invalid/'
                && $request->hasHeader('User-Agent', 'WebsiteEstateMonitor/1.0')
                && ! $request->hasHeader('Authorization');
        });
        $this->assertSame(17, $capturedOptions['timeout']);
        $this->assertSame(5, $capturedOptions['connect_timeout']);
        $this->assertSame(7, $capturedOptions['allow_redirects']['max']);
        $this->assertSame(['https'], $capturedOptions['allow_redirects']['protocols']);
        $this->assertTrue($capturedOptions['allow_redirects']['track_redirects']);
        $this->assertTrue($capturedOptions['verify'] ?? true);
        $this->assertFalse($capturedOptions['cookies']);
    }

    #[DataProvider('responseStatusProvider')]
    public function test_any_received_http_response_is_a_successful_observation(int $status): void
    {
        Http::fake(['*' => Http::response('body that must not escape', $status)]);

        $result = $this->probe()->check('example.invalid');

        $this->assertTrue($result->successful);
        $this->assertSame($status, $result->httpStatus);
        $this->assertIsInt($result->responseTimeMs);
        $this->assertGreaterThanOrEqual(0, $result->responseTimeMs);
        $this->assertSame('https://example.invalid/', $result->finalUrl);
        $this->assertSame(0, $result->redirectCount);
        $this->assertNull($result->errorType);
        $this->assertNull($result->errorMessage);
    }

    /** @return iterable<string, array{int}> */
    public static function responseStatusProvider(): iterable
    {
        yield 'OK' => [200];
        yield 'not found' => [404];
        yield 'server error' => [500];
    }

    #[DataProvider('redirectHistoryProvider')]
    public function test_tracked_redirects_produce_count_and_final_https_url(array $history, int $count, string $finalUrl): void
    {
        Http::fake(['*' => Http::response('', 200, [
            'X-Guzzle-Redirect-History' => $history,
        ])]);

        $result = $this->probe()->check('redirect.example.invalid');

        $this->assertTrue($result->successful);
        $this->assertSame($count, $result->redirectCount);
        $this->assertSame($finalUrl, $result->finalUrl);
        $this->assertObjectNotHasProperty('metadata', $result);
    }

    /** @return iterable<string, array{list<string>, int, string}> */
    public static function redirectHistoryProvider(): iterable
    {
        yield 'one hop' => [
            ['https://redirect.example.invalid/final'],
            1,
            'https://redirect.example.invalid/final',
        ];
        yield 'multiple hops' => [
            ['https://redirect.example.invalid/first', 'https://redirect.example.invalid/final'],
            2,
            'https://redirect.example.invalid/final',
        ];
    }

    public function test_insecure_redirect_history_is_a_transport_failure(): void
    {
        Http::fake(['*' => Http::response('', 200, [
            'X-Guzzle-Redirect-History' => ['http://redirect.example.invalid/insecure'],
        ])]);

        $result = $this->probe()->check('redirect.example.invalid');

        $this->assertFalse($result->successful);
        $this->assertSame('transport_error', $result->errorType);
        $this->assertNull($result->finalUrl);
    }

    #[DataProvider('transportFailureProvider')]
    public function test_transport_failures_are_safely_categorized(string $message, string $errorType): void
    {
        Http::fake(['*' => Http::failedConnection($message)]);

        $result = $this->probe()->check('broken.example.invalid');

        $this->assertFalse($result->successful);
        $this->assertNull($result->httpStatus);
        $this->assertSame($errorType, $result->errorType);
        $this->assertNotNull($result->errorMessage);
        $this->assertLessThanOrEqual(160, mb_strlen($result->errorMessage));
        $this->assertStringNotContainsString('sensitive-fixture', $result->errorMessage);
    }

    /** @return iterable<string, array{string, string}> */
    public static function transportFailureProvider(): iterable
    {
        yield 'connection' => ['Could not resolve host sensitive-fixture', 'connection_failed'];
        yield 'timeout' => ['cURL error 28: Operation timed out sensitive-fixture', 'timeout'];
        yield 'TLS' => ['SSL certificate problem sensitive-fixture', 'tls_failed'];
        yield 'redirect limit' => ['Will not follow more than 10 redirects sensitive-fixture', 'too_many_redirects'];
        yield 'general transport' => ['Unexpected transport condition sensitive-fixture', 'transport_error'];
    }

    public function test_result_dto_has_explicit_response_and_failure_invariants_without_a_body(): void
    {
        $response = HttpResult::response(301, 12, 'https://example.invalid/final', 1);
        $failure = HttpResult::failure('timeout', 'HTTP request timed out.', 10);

        $this->assertTrue($response->successful);
        $this->assertSame(301, $response->httpStatus);
        $this->assertNull($response->errorType);
        $this->assertFalse($failure->successful);
        $this->assertNull($failure->httpStatus);
        $this->assertSame('timeout', $failure->errorType);
        $this->assertFalse((new ReflectionClass(HttpResult::class))->hasProperty('body'));
    }

    private function probe(): HttpProbe
    {
        return new HttpProbe(new MonitoringThresholds);
    }
}
