<?php

namespace Tests\Feature\Scheduling;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class EstateScheduleTest extends TestCase
{
    public function test_estate_schedule_uses_configured_cadences_and_overlap_protection(): void
    {
        $events = collect($this->app->make(Schedule::class)->events());

        $expected = [
            'estate:dispatch-server-checks' => '0-59/5 * * * *',
            'estate:dispatch-domain-checks http' => '2-59/10 * * * *',
            'estate:dispatch-domain-checks dns' => '4 */6 * * *',
            'estate:dispatch-domain-checks tls' => '7 */6 * * *',
            'estate:dispatch-inventory' => '0 3 * * *',
            'model:prune' => '0 0 * * *',
        ];

        foreach ($expected as $command => $expression) {
            $event = $events->first(fn (Event $event) => str_contains($event->command, $command));
            $this->assertNotNull($event, "Missing scheduled event {$command}");
            $this->assertSame($expression, $event->expression);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertFalse($event->onOneServer);
        }

        $inventory = $events->first(fn (Event $event) => str_contains($event->command, 'estate:dispatch-inventory'));
        $this->assertSame('Europe/London', $inventory->timezone);

        $prune = $events->first(fn (Event $event) => str_contains($event->command, 'model:prune'));
        $this->assertStringContainsString('App\\Models\\DomainCheck', $prune->command);
        $this->assertStringContainsString('App\\Models\\ServerCheck', $prune->command);
    }
}
