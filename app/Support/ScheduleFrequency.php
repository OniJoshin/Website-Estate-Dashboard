<?php

namespace App\Support;

use InvalidArgumentException;

final class ScheduleFrequency
{
    public static function cron(int $minutes, int $offset = 0): string
    {
        if ($minutes > 0 && $minutes < 60 && 60 % $minutes === 0) {
            $start = $offset % $minutes;

            return "{$start}-59/{$minutes} * * * *";
        }

        $hours = intdiv($minutes, 60);

        if ($minutes >= 60 && $minutes % 60 === 0 && 24 % $hours === 0) {
            return "{$offset} */{$hours} * * *";
        }

        throw new InvalidArgumentException("Unsupported estate schedule interval: {$minutes} minutes.");
    }
}
