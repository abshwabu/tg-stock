<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

class EthiopicCalendar
{
    protected const AMHARIC_MONTHS = [
        1 => 'መስከረም',
        2 => 'ጥቅምት',
        3 => 'ኅዳር',
        4 => 'ታኅሣሥ',
        5 => 'ጥር',
        6 => 'የካቲት',
        7 => 'መጋቢት',
        8 => 'ሚያዝያ',
        9 => 'ግንቦት',
        10 => 'ሰኔ',
        11 => 'ሐምሌ',
        12 => 'ነሐሴ',
        13 => 'ጳጉሜ',
    ];

    protected const ENGLISH_MONTHS = [
        1 => 'Meskerem',
        2 => 'Tikimt',
        3 => 'Hidar',
        4 => 'Tahsas',
        5 => 'Tir',
        6 => 'Yakatit',
        7 => 'Megabit',
        8 => 'Miyazya',
        9 => 'Ginbot',
        10 => 'Sene',
        11 => 'Hamle',
        12 => 'Nehase',
        13 => 'Pagume',
    ];

    /**
     * Convert a Carbon / DateTime instance to an Ethiopian Date array:
     * [year, month, day, month_name_am, month_name_en]
     *
     * @return array{year: int, month: int, day: int, month_name: string}
     */
    public static function fromGregorian(CarbonInterface $date, string $locale = 'am'): array
    {
        $gy = (int) $date->format('Y');
        $gm = (int) $date->format('n');
        $gd = (int) $date->format('j');

        // Calculate Julian Day Number from Gregorian Date
        $a = (int) ((14 - $gm) / 12);
        $y = $gy + 4800 - $a;
        $m = $gm + 12 * $a - 3;
        $jdn = $gd + (int) ((153 * $m + 2) / 5) + 365 * $y + (int) ($y / 4) - (int) ($y / 100) + (int) ($y / 400) - 32045;

        // Convert Julian Day Number to Ethiopic Calendar
        $r = ($jdn - 1723856) % 1461;
        $n = ($r % 365) + 365 * (int) ($r / 1460);
        $ey = 4 * (int) (($jdn - 1723856) / 1461) + (int) ($r / 365) - (int) ($r / 1460);
        $em = (int) ($n / 30) + 1;
        $ed = ($n % 30) + 1;

        $monthName = $locale === 'en'
            ? (self::ENGLISH_MONTHS[$em] ?? '')
            : (self::AMHARIC_MONTHS[$em] ?? '');

        return [
            'year' => $ey,
            'month' => $em,
            'day' => $ed,
            'month_name' => $monthName,
        ];
    }

    /**
     * Format a date into human-readable Ethiopian format:
     * e.g. "ጥቅምት 24, 2019" or "Tikimt 24, 2019"
     */
    public static function format(CarbonInterface $date, string $locale = 'am'): string
    {
        $eth = self::fromGregorian($date, $locale);
        return "{$eth['month_name']} {$eth['day']}, {$eth['year']}";
    }

    /**
     * Format money into ETB currency string: e.g. "1,250.00 ETB"
     */
    public static function formatMoney(int $cents): string
    {
        return number_format($cents / 100, 2) . ' ETB';
    }
}
