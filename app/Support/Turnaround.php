<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The 20-day turnaround of the Action Officer, counted in working days (Monday to Friday).
 * Holidays are not known here, so a holiday counts as a working day: the clock is never
 * shorter than the true one.
 */
class Turnaround
{
    public static function days(): int
    {
        return (int) config('hrmm.turnaround_days', 20);
    }

    /** The date the clock ends when it starts at `$from`. */
    public static function dueFrom(CarbonInterface $from): CarbonImmutable
    {
        $date = CarbonImmutable::instance($from)->startOfDay();
        $left = self::days();

        while ($left > 0) {
            $date = $date->addDay();

            if (! $date->isWeekend()) {
                $left--;
            }
        }

        return $date->endOfDay();
    }

    /** Working days from today to `$due`: positive while time is left, negative once overdue. */
    public static function daysLeft(CarbonInterface $due, ?CarbonInterface $today = null): int
    {
        $day = CarbonImmutable::instance($today ?? now())->startOfDay();
        $end = CarbonImmutable::instance($due)->startOfDay();
        $sign = $end >= $day ? 1 : -1;
        [$from, $to] = $sign === 1 ? [$day, $end] : [$end, $day];

        $count = 0;
        for ($d = $from->addDay(); $d <= $to; $d = $d->addDay()) {
            if (! $d->isWeekend()) {
                $count++;
            }
        }

        return $sign * $count;
    }
}
