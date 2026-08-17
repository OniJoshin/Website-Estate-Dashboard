<?php

namespace App\Jobs\Monitoring;

use App\Models\Server;
use App\Services\Whm\Contracts\WhmClient;
use App\Services\Whm\Exceptions\WhmApiException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckServerHealth implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $serverId) {}

    public function handle(WhmClient $client): void
    {
        $server = Server::query()->find($this->serverId);

        if ($server === null || ! $server->enabled) {
            return;
        }

        try {
            $health = $client->getServerHealth($server);
        } catch (WhmApiException) {
            $server->serverChecks()->create([
                'checked_at' => now(),
                'reachable' => false,
                'error_message' => 'WHM server health check failed.',
            ]);

            return;
        }

        $server->serverChecks()->create([
            'checked_at' => now(),
            'reachable' => true,
            'load_1m' => $health->load1,
            'load_5m' => $health->load5,
            'load_15m' => $health->load15,
            'partitions' => $health->partitions,
            'error_message' => null,
        ]);
    }
}
