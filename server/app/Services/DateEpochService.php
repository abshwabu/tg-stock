<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeZone;

class DateEpochService
{
    public const TIMEZONE = 'Africa/Addis_Ababa';
    public const EPOCH_DATE = '2024-01-01';
    public const DAYS_BETWEEN_1970_AND_2024 = 19723;
    public const LIFETIME_EXP_DAY = 0xFFFF; // 65535

    /**
     * Compute days since 2024-01-01 in Africa/Addis_Ababa.
     */
    public static function dateToDay(CarbonInterface|\DateTimeInterface|string|null $date = null): int
    {
        $tz = new DateTimeZone(self::TIMEZONE);
        if ($date === null) {
            $carbon = Carbon::now($tz);
        } elseif (is_string($date)) {
            $carbon = Carbon::parse($date, $tz)->setTimezone($tz);
        } else {
            $carbon = Carbon::instance($date)->setTimezone($tz);
        }

        // day = floor((unix_timestamp + 10800) / 86400) - 19723
        $timestamp = $carbon->getTimestamp();
        return (int) (floor(($timestamp + 10800) / 86400) - self::DAYS_BETWEEN_1970_AND_2024);
    }

    /**
     * Convert day index since 2024-01-01 to Carbon date in Africa/Addis_Ababa.
     */
    public static function dayToDate(int $day): Carbon
    {
        $tz = new DateTimeZone(self::TIMEZONE);
        $daysFrom1970 = $day + self::DAYS_BETWEEN_1970_AND_2024;
        $timestamp = ($daysFrom1970 * 86400) - 10800;

        return Carbon::createFromTimestamp($timestamp, $tz)->startOfDay();
    }

    /**
     * Get current day index in Addis Ababa.
     */
    public static function today(): int
    {
        return self::dateToDay();
    }
}
