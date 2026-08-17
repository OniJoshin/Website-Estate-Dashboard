<?php

use App\Models\DomainCheck;
use App\Models\ServerCheck;
use App\Support\ScheduleFrequency;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('estate:dispatch-server-checks')
    ->cron(ScheduleFrequency::cron((int) config('estate.schedule.server_minutes')))
    ->withoutOverlapping();
Schedule::command('estate:dispatch-domain-checks http')
    ->cron(ScheduleFrequency::cron((int) config('estate.schedule.http_minutes'), 2))
    ->withoutOverlapping();
Schedule::command('estate:dispatch-domain-checks dns')
    ->cron(ScheduleFrequency::cron((int) config('estate.schedule.dns_minutes'), 4))
    ->withoutOverlapping();
Schedule::command('estate:dispatch-domain-checks tls')
    ->cron(ScheduleFrequency::cron((int) config('estate.schedule.tls_minutes'), 7))
    ->withoutOverlapping();
Schedule::command('estate:dispatch-inventory')
    ->dailyAt((string) config('estate.schedule.inventory_time'))
    ->timezone((string) config('estate.schedule.inventory_timezone'))
    ->withoutOverlapping();
Schedule::command('model:prune', [
    '--model' => [DomainCheck::class, ServerCheck::class],
])->daily()->withoutOverlapping();

// Production must invoke schedule:run every minute and run persistent queue workers.
// Review these events for onOneServer() before introducing multiple scheduler nodes.
