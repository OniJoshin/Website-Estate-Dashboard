<?php

namespace App\Jobs\Monitoring;

use App\Models\Server;
use App\Services\Monitoring\IssueEvaluator;
use App\Services\Whm\Contracts\WhmClient;
use App\Services\Whm\Exceptions\WhmApiException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CheckServerHealth implements ShouldQueue
{
    use Queueable;

    private const int LOCK_SECONDS = 10;

    private const int LOCK_WAIT_SECONDS = 5;

    public int $tries = 1;

    public function __construct(public int $serverId) {}

    public function handle(WhmClient $client, IssueEvaluator $evaluator): void
    {
        $server = Server::query()->find($this->serverId);

        if ($server === null || ! $server->enabled) {
            return;
        }

        try {
            $health = $client->getServerHealth($server);
            $attributes = [
                'checked_at' => now(),
                'reachable' => true,
                'load_1m' => $health->load1,
                'load_5m' => $health->load5,
                'load_15m' => $health->load15,
                'partitions' => $health->partitions,
                'error_message' => null,
            ];
        } catch (WhmApiException) {
            $attributes = [
                'checked_at' => now(),
                'reachable' => false,
                'error_message' => 'WHM server health check failed.',
            ];
        }

        Cache::lock("estate:observation:server:{$server->id}:health", self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, function () use ($server, $attributes, $evaluator): void {
                DB::transaction(function () use ($server, $attributes, $evaluator): void {
                    $check = $server->serverChecks()->create($attributes);
                    $evaluator->evaluateServerCheck($check);
                });
            });
    }
}
