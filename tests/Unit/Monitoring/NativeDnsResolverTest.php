<?php

namespace Tests\Unit\Monitoring;

use App\Data\Monitoring\DnsResult;
use App\Services\Monitoring\NativeDnsResolver;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NativeDnsResolverTest extends TestCase
{
    /** @var list<array{string, int}> */
    private array $calls = [];

    #[DataProvider('singleRecordTypeProvider')]
    public function test_each_relevant_record_type_can_produce_a_successful_result(int $type, array $record, array $expected): void
    {
        $result = $this->resolverWith([$type => [$record]])->resolve('example.invalid');

        $this->assertTrue($result->successful);
        $this->assertSame($expected['a'], $result->a);
        $this->assertSame($expected['aaaa'], $result->aaaa);
        $this->assertSame($expected['cname'], $result->cname);
        $this->assertNull($result->errorType);
        $this->assertNull($result->errorMessage);
    }

    /** @return iterable<string, array{int, array<string, string>, array{a: list<string>, aaaa: list<string>, cname: list<string>}}> */
    public static function singleRecordTypeProvider(): iterable
    {
        yield 'A' => [DNS_A, ['type' => 'A', 'ip' => '192.0.2.1'], ['a' => ['192.0.2.1'], 'aaaa' => [], 'cname' => []]];
        yield 'AAAA' => [DNS_AAAA, ['type' => 'AAAA', 'ipv6' => '2001:db8::1'], ['a' => [], 'aaaa' => ['2001:db8::1'], 'cname' => []]];
        yield 'CNAME' => [DNS_CNAME, ['type' => 'CNAME', 'target' => 'origin.example.invalid'], ['a' => [], 'aaaa' => [], 'cname' => ['origin.example.invalid']]];
    }

    public function test_records_are_validated_deduplicated_normalized_and_sorted(): void
    {
        $result = $this->resolverWith([
            DNS_A => [
                ['type' => 'A', 'ip' => '192.0.2.20'],
                ['type' => 'A', 'ip' => 'not-an-ip'],
                ['type' => 'A', 'ip' => '192.0.2.10'],
                ['type' => 'A', 'ip' => '192.0.2.20'],
                ['type' => 'MX', 'ip' => '192.0.2.30'],
            ],
            DNS_AAAA => [
                ['type' => 'AAAA', 'ipv6' => '2001:db8::2'],
                ['type' => 'AAAA', 'ipv6' => 'invalid'],
                ['type' => 'AAAA', 'ipv6' => '2001:db8::1'],
                ['type' => 'AAAA', 'ipv6' => '2001:db8::2'],
            ],
            DNS_CNAME => [
                ['type' => 'CNAME', 'target' => ' Zeta.Example.Invalid. '],
                ['type' => 'CNAME', 'target' => 'alpha.example.invalid'],
                ['type' => 'CNAME', 'target' => 'zeta.example.invalid'],
                ['type' => 'CNAME', 'target' => 'bad hostname'],
                ['type' => 'CNAME', 'target' => ''],
            ],
        ])->resolve('example.invalid');

        $this->assertSame(['192.0.2.10', '192.0.2.20'], $result->a);
        $this->assertSame(['2001:db8::1', '2001:db8::2'], $result->aaaa);
        $this->assertSame(['alpha.example.invalid', 'zeta.example.invalid'], $result->cname);
    }

    public function test_all_empty_answers_produce_no_records_failure(): void
    {
        $result = $this->resolverWith([])->resolve('example.invalid');

        $this->assertFalse($result->successful);
        $this->assertSame([], $result->a);
        $this->assertSame([], $result->aaaa);
        $this->assertSame([], $result->cname);
        $this->assertSame('no_records', $result->errorType);
        $this->assertSame('No relevant DNS records were found.', $result->errorMessage);
    }

    public function test_native_failure_without_useful_records_produces_safe_resolver_error(): void
    {
        $resolver = new NativeDnsResolver(function (string $hostname, int $type): false {
            trigger_error('nameserver /secret/path failed on line 123', E_USER_WARNING);

            return false;
        });

        $result = $resolver->resolve('example.invalid');

        $this->assertFalse($result->successful);
        $this->assertSame('resolver_error', $result->errorType);
        $this->assertSame('DNS resolution failed.', $result->errorMessage);
        $this->assertStringNotContainsString('secret', $result->errorMessage);
    }

    public function test_one_failed_or_empty_record_type_does_not_invalidate_useful_records(): void
    {
        $result = $this->resolverWith([
            DNS_A => false,
            DNS_CNAME => [['type' => 'CNAME', 'target' => 'origin.example.invalid']],
        ])->resolve('example.invalid');

        $this->assertTrue($result->successful);
        $this->assertSame(['origin.example.invalid'], $result->cname);
    }

    public function test_only_explicit_relevant_record_types_are_queried_for_original_hostname(): void
    {
        $this->resolverWith([
            DNS_CNAME => [['type' => 'CNAME', 'target' => 'origin.example.invalid']],
        ])->resolve('example.invalid');

        $this->assertSame([
            ['example.invalid', DNS_A],
            ['example.invalid', DNS_AAAA],
            ['example.invalid', DNS_CNAME],
        ], $this->calls);
    }

    public function test_resolved_result_requires_at_least_one_record(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DnsResult::resolved([], [], []);
    }

    /** @param array<int, array<int, array<string, mixed>>|false> $answers */
    private function resolverWith(array $answers): NativeDnsResolver
    {
        $lookup = function (string $hostname, int $type) use ($answers): array|false {
            $this->calls[] = [$hostname, $type];

            return $answers[$type] ?? [];
        };

        return new NativeDnsResolver(Closure::fromCallable($lookup));
    }
}
