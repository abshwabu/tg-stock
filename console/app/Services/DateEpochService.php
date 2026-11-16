<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

class DateEpochService
{
    public const TIMEZONE = 'Africa/Addis_Ababa';
    public const EPOCH_DATE = '2024-01-01 00:00:00';

    private static ?DateTimeZone $tz = null;
    private static ?DateTimeImmutable $epoch = null;

    public static function getTimeZone(): DateTimeZone
    {
        if (self::$tz === null) {
            self::$tz = new DateTimeZone(self::TIMEZONE);
        }
        return self::$tz;
    }

    public static function getEpoch(): DateTimeImmutable
    {
        if (self::$epoch === null) {
            self::$epoch = new DateTimeImmutable(self::EPOCH_DATE, self::getTimeZone());
        }
        return self::$epoch;
    }

    /**
     * Get current integer day index in Africa/Addis_Ababa.
     */
    public static function todayDay(): int
    {
        $now = new DateTimeImmutable('now', self::getTimeZone());
        return self::dateToDay($now->format('Y-m-d'));
    }

    /**
     * Convert an integer day count to a formatted Y-m-d date in Africa/Addis_Ababa.
     */
    public static function dayToDate(int $day): string
    {
        if ($day === 0xFFFF) {
            return 'Lifetime (Never)';
        }
        return self::getEpoch()->modify("+{$day} days")->format('Y-m-d');
    }

    /**
     * Convert Y-m-d date string into integer day count since 2024-01-01.
     */
    public static function dateToDay(string $dateStr): int
    {
        $d = new DateTimeImmutable(substr($dateStr, 0, 10) . ' 00:00:00', self::getTimeZone());
        $diff = self::getEpoch()->diff($d);
        return (int)$diff->format('%r%a');
    }
}
