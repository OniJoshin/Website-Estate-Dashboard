<?php

namespace Tests\Feature\Scheduling;

use App\Jobs\Monitoring\CheckDomainDns;
use App\Jobs\Monitoring\CheckDomainHttp;
use App\Jobs\Monitoring\CheckDomainTls;
use App\Jobs\Monitoring\CheckServerHealth;
use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MonitoringJobQueuePolicyTest extends TestCase
{
    /** @return array<string, array{object}> */
    public static function monitoringJobs(): array
    {
        return [
            'HTTP' => [new CheckDomainHttp(10)],
            'DNS' => [new CheckDomainDns(10)],
            'TLS' => [new CheckDomainTls(10)],
            'server' => [new CheckServerHealth(10)],
        ];
    }

    #[DataProvider('monitoringJobs')]
    public function test_monitoring_jobs_are_unique_with_bounded_retries(object $job): void
    {
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('10', $job->uniqueId());
        $this->assertSame(900, $job->uniqueFor);
        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 30], $job->backoff());
    }

    public function test_unique_identity_varies_by_target_and_job_type(): void
    {
        $lock = new UniqueLock(Cache::store());

        $this->assertSame($lock->getKey(new CheckDomainHttp(5)), $lock->getKey(new CheckDomainHttp(5)));
        $this->assertNotSame($lock->getKey(new CheckDomainHttp(5)), $lock->getKey(new CheckDomainHttp(6)));
        $this->assertNotSame($lock->getKey(new CheckDomainHttp(5)), $lock->getKey(new CheckDomainDns(5)));
        $this->assertNotSame($lock->getKey(new CheckDomainDns(5)), $lock->getKey(new CheckDomainTls(5)));
    }

    public function test_database_cache_store_supports_atomic_locks(): void
    {
        $store = Cache::store('database')->getStore();

        $this->assertInstanceOf(DatabaseStore::class, $store);
        $this->assertInstanceOf(LockProvider::class, $store);
    }
}
