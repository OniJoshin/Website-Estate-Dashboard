<?php

namespace App\Support\Operations;

use App\Data\Operations\HeartbeatStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class OperationsStatus
{
    public const string QUEUE_HEARTBEAT_KEY = 'estate:operations:queue-heartbeat';

    public const string SCHEDULER_HEARTBEAT_KEY = 'estate:operations:scheduler-heartbeat';

    private readonly int $staleMinutes;

    public function __construct()
    {
        $this->staleMinutes = (int) config('estate.operations.heartbeat_stale_minutes');

        if ($this->staleMinutes < 1) {
            throw new InvalidArgumentException('The operations heartbeat stale threshold must be positive.');
        }
    }

    public function schedulerHeartbeat(): HeartbeatStatus
    {
        return $this->heartbeat(self::SCHEDULER_HEARTBEAT_KEY);
    }

    public function queueHeartbeat(): HeartbeatStatus
    {
        return $this->heartbeat(self::QUEUE_HEARTBEAT_KEY);
    }

    /** @return array{pending: int, failed: int, oldest_pending_at: ?CarbonImmutable} */
    public function queueMetrics(): array
    {
        $queue = $this->queueDatabase();
        $failed = $this->failedJobDatabase();
        $oldest = $queue->table((string) config('queue.connections.database.table', 'jobs'))->min('created_at');

        return [
            'pending' => $queue->table((string) config('queue.connections.database.table', 'jobs'))->count(),
            'failed' => $failed->table((string) config('queue.failed.table', 'failed_jobs'))->count(),
            'oldest_pending_at' => is_numeric($oldest) ? CarbonImmutable::createFromTimestampUTC((int) $oldest) : null,
        ];
    }

    /** @return list<array{failed_at: CarbonImmutable, connection: string, queue: string, job: string}> */
    public function recentFailedJobs(): array
    {
        return array_values($this->failedJobDatabase()->table((string) config('queue.failed.table', 'failed_jobs'))->latest('failed_at')->limit(20)->get()
            ->map(function (object $record): array {
                $payload = json_decode((string) $record->payload, true);
                $displayName = is_array($payload) && is_string($payload['displayName'] ?? null)
                    ? $payload['displayName']
                    : null;
                $job = $displayName !== null && preg_match('/^App\\\\Jobs\\\\[A-Za-z0-9_\\\\]+$/', $displayName) === 1
                    ? class_basename($displayName)
                    : 'Unknown job';

                return [
                    'failed_at' => CarbonImmutable::parse($record->failed_at),
                    'connection' => (string) $record->connection,
                    'queue' => (string) $record->queue,
                    'job' => $job,
                ];
            })->all());
    }

    /** @return array<string, string|bool> */
    public function applicationInformation(): array
    {
        return [
            'environment' => app()->environment(),
            'debug' => app()->hasDebugModeEnabled(),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'database' => DB::connection()->getDriverName(),
            'cache' => (string) config('cache.default'),
            'queue' => (string) config('queue.default'),
        ];
    }

    /** @return list<string> */
    public function scheduleSummary(): array
    {
        return [
            'Server health: every '.config('estate.schedule.server_minutes').' minutes',
            'HTTP: every '.config('estate.schedule.http_minutes').' minutes',
            'DNS: every '.$this->minutesForDisplay((int) config('estate.schedule.dns_minutes')),
            'TLS: every '.$this->minutesForDisplay((int) config('estate.schedule.tls_minutes')),
            'Inventory: daily at '.config('estate.schedule.inventory_time').' '.config('estate.schedule.inventory_timezone'),
            'Retention: '.config('estate.retention.check_days').' days',
        ];
    }

    private function heartbeat(string $key): HeartbeatStatus
    {
        $value = Cache::get($key);

        if (! is_string($value)) {
            return new HeartbeatStatus('Never seen', null);
        }

        try {
            $recordedAt = CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return new HeartbeatStatus('Never seen', null);
        }

        $state = $recordedAt->greaterThan(now()->subMinutes($this->staleMinutes))
            ? 'Current'
            : 'Stale';

        return new HeartbeatStatus($state, $recordedAt);
    }

    private function minutesForDisplay(int $minutes): string
    {
        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return "{$hours} ".Str::plural('hour', $hours);
        }

        return "{$minutes} ".Str::plural('minute', $minutes);
    }

    private function queueDatabase(): ConnectionInterface
    {
        return DB::connection(config('queue.connections.database.connection'));
    }

    private function failedJobDatabase(): ConnectionInterface
    {
        return DB::connection((string) config('queue.failed.database'));
    }
}
