<?php

namespace Tests\Unit\Scheduling;

use App\Support\ScheduleFrequency;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScheduleFrequencyTest extends TestCase
{
    #[DataProvider('supportedIntervals')]
    public function test_it_builds_exact_cron_for_supported_intervals(int $minutes, int $offset, string $expected): void
    {
        $this->assertSame($expected, ScheduleFrequency::cron($minutes, $offset));
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function supportedIntervals(): iterable
    {
        yield 'one minute ignores a larger stagger safely' => [1, 2, '0-59/1 * * * *'];
        yield 'five minutes' => [5, 0, '0-59/5 * * * *'];
        yield 'ten minutes staggered' => [10, 2, '2-59/10 * * * *'];
        yield 'six hours staggered' => [360, 4, '4 */6 * * *'];
    }

    #[DataProvider('unsupportedIntervals')]
    public function test_unsupported_intervals_fail_fast(int $minutes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported estate schedule interval: {$minutes} minutes.");

        ScheduleFrequency::cron($minutes);
    }

    /** @return iterable<string, array{int}> */
    public static function unsupportedIntervals(): iterable
    {
        yield 'non-positive' => [0];
        yield 'does not divide hour' => [7];
        yield 'hour interval does not divide day' => [90];
    }
}
