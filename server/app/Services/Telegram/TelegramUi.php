<?php

declare(strict_types=1);

namespace App\Services\Telegram;

class TelegramUi
{
    /**
     * Build an inline keyboard markup.
     *
     * @param array<int, array<int, array{text: string, callback_data?: string, url?: string}>> $rows
     * @return array{inline_keyboard: array}
     */
    public static function inlineKeyboard(array $rows): array
    {
        return [
            'inline_keyboard' => $rows,
        ];
    }

    /**
     * Build a custom reply keyboard markup.
     *
     * @param array<int, array<int, string>> $rows
     * @param bool $resize
     * @param bool $oneTime
     * @return array{keyboard: array, resize_keyboard: bool, one_time_keyboard: bool}
     */
    public static function replyKeyboard(array $rows, bool $resize = true, bool $oneTime = false, bool $isPersistent = true): array
    {
        $formatted = [];
        foreach ($rows as $row) {
            $formattedRow = [];
            foreach ($row as $btn) {
                $formattedRow[] = is_string($btn) ? ['text' => $btn] : $btn;
            }
            $formatted[] = $formattedRow;
        }

        return [
            'keyboard' => $formatted,
            'resize_keyboard' => $resize,
            'one_time_keyboard' => $oneTime,
            'is_persistent' => $isPersistent,
        ];
    }

    /**
     * Standard persistent main menu reply keyboard:
     * Location switcher (if multi-location) · 📦 Products · ➕ Stock In · 🛒 Sale · 📊 Reports · 🚚 Transfer · ⚙️ Settings
     */
    public static function mainMenuKeyboard(string $locale = 'am', ?\App\Models\User $user = null): array
    {
        if ($user === null) {
            if ($locale === 'en') {
                return self::replyKeyboard([
                    ['📦 Products', '➕ Stock In'],
                    ['🛒 Sale', '📊 Reports'],
                    ['⚙️ Settings'],
                ]);
            }

            return self::replyKeyboard([
                ['📦 ዕቃዎች', '➕ ገቢ ዕቃ'],
                ['🛒 ሽያጭ', '📊 ሪፖርት'],
                ['⚙️ ቅንብሮች'],
            ]);
        }

        $rows = [];

        // 1. Location Switcher (only shown if user has more than 1 allowed location)
        $locHandler = app(\App\Services\Telegram\Handlers\LocationHandler::class);
        $allowedLocations = $locHandler->getAllowedLocations($user);
        if ($allowedLocations->count() > 1) {
            $curLoc = $locHandler->getCurrentLocation($user);
            $locName = $curLoc ? $curLoc->name : 'Location';
            $rows[] = ["📍 {$locName} ▾"];
        }

        // 2. Permission-gated action buttons
        $perm = app(\App\Services\PermissionService::class);
        $canProducts = $perm->can($user, 'products.view');
        $canStockIn = $perm->can($user, 'stock.in');
        $canSale = $perm->can($user, 'sale.create');
        $canReports = $perm->can($user, 'reports.view') || $perm->can($user, 'reports.own_sales');
        $canTransfer = $perm->can($user, 'transfer.create') && ($allowedLocations->count() > 1);

        $row1 = [];
        if ($canProducts) {
            $row1[] = $locale === 'en' ? '📦 Products' : '📦 ዕቃዎች';
        }
        if ($canStockIn) {
            $row1[] = $locale === 'en' ? '➕ Stock In' : '➕ ገቢ ዕቃ';
        }
        if (!empty($row1)) {
            $rows[] = $row1;
        }

        $row2 = [];
        if ($canSale) {
            $row2[] = $locale === 'en' ? '🛒 Sale' : '🛒 ሽያጭ';
        }
        if ($canReports) {
            $row2[] = $locale === 'en' ? '📊 Reports' : '📊 ሪፖርት';
        }
        if (!empty($row2)) {
            $rows[] = $row2;
        }

        if ($canTransfer) {
            $rows[] = [$locale === 'en' ? '🚚 Transfer' : '🚚 ማስተላለፍ'];
        }

        // 3. Settings is always available
        $rows[] = [$locale === 'en' ? '⚙️ Settings' : '⚙️ ቅንብሮች'];

        return self::replyKeyboard($rows);
    }

    /**
     * Inline keyboard for the main menu, directly embedded under messages.
     */
    public static function mainMenuInlineKeyboard(string $locale = 'am', ?\App\Models\User $user = null): array
    {
        $rows = [];

        // 1. Location Switcher (if multi-location)
        if ($user !== null) {
            $locHandler = app(\App\Services\Telegram\Handlers\LocationHandler::class);
            $allowedLocations = $locHandler->getAllowedLocations($user);
            if ($allowedLocations->count() > 1) {
                $curLoc = $locHandler->getCurrentLocation($user);
                $locName = $curLoc ? $curLoc->name : 'Location';
                $rows[] = [
                    ['text' => "📍 {$locName} ▾", 'callback_data' => 'menu:switch_loc:0'],
                ];
            }
        }

        $row1 = [
            ['text' => $locale === 'en' ? '📦 Products' : '📦 ዕቃዎች', 'callback_data' => 'menu:products:0'],
            ['text' => $locale === 'en' ? '➕ Stock In' : '➕ ገቢ ዕቃ', 'callback_data' => 'menu:stockin:0'],
        ];
        $rows[] = $row1;

        $row2 = [
            ['text' => $locale === 'en' ? '🛒 Sale' : '🛒 ሽያጭ', 'callback_data' => 'menu:sale:0'],
            ['text' => $locale === 'en' ? '📊 Reports' : '📊 ሪፖርት', 'callback_data' => 'menu:reports:0'],
        ];
        $rows[] = $row2;

        $row3 = [];
        if ($user !== null && $user->role === 'owner') {
            $row3[] = ['text' => $locale === 'en' ? '📱 Link Phone' : '📱 ስልክ አገናኝ', 'callback_data' => 'menu:pair:0'];
            $row3[] = ['text' => $locale === 'en' ? '🚚 Transfer' : '🚚 ዝውውር', 'callback_data' => 'menu:transfer:0'];
        }
        if (!empty($row3)) {
            $rows[] = $row3;
        }

        $row4 = [];
        if ($user !== null && $user->role === 'owner') {
            $row4[] = ['text' => $locale === 'en' ? '👥 Team' : '👥 ቡድን', 'callback_data' => 'menu:team:0'];
            $row4[] = ['text' => $locale === 'en' ? '⚙️ Settings' : '⚙️ ቅንብሮች', 'callback_data' => 'menu:settings:0'];
        } else {
            $row4[] = ['text' => $locale === 'en' ? '⚙️ Settings' : '⚙️ ቅንብሮች', 'callback_data' => 'menu:settings:0'];
        }
        $rows[] = $row4;

        return self::inlineKeyboard($rows);
    }

    /**
     * Cancel keyboard shown during active conversation steps.
     */
    public static function cancelKeyboard(string $locale = 'am'): array
    {
        $cancelLabel = $locale === 'en' ? '❌ Cancel' : '❌ ሰርዝ';

        return self::replyKeyboard([
            [$cancelLabel],
        ]);
    }

    /**
     * Build standard Yes / No inline confirmation prompt buttons.
     */
    public static function confirmKeyboard(string $yesCallback, string $noCallback, string $locale = 'am'): array
    {
        $yesLabel = $locale === 'en' ? '✅ Yes' : '✅ አዎ';
        $noLabel = $locale === 'en' ? '❌ No' : '❌ አይ';

        return self::inlineKeyboard([
            [
                ['text' => $yesLabel, 'callback_data' => $yesCallback],
                ['text' => $noLabel, 'callback_data' => $noCallback],
            ],
        ]);
    }

    /**
     * Build pagination inline controls: [◀️ Prev] [ 1 / 5 ] [Next ▶️]
     *
     * @param int $page 1-based index
     * @param int $totalPages
     * @param string $prefix Module prefix for callback data, e.g. "prod:page"
     */
    public static function paginator(int $page, int $totalPages, string $prefix, string $locale = 'am'): ?array
    {
        if ($totalPages <= 1) {
            return null;
        }

        $row = [];

        if ($page > 1) {
            $prevPage = $page - 1;
            $row[] = [
                'text' => '◀️ ' . ($locale === 'en' ? 'Prev' : 'ወደኋላ'),
                'callback_data' => "{$prefix}:{$prevPage}",
            ];
        }

        $row[] = [
            'text' => "{$page} / {$totalPages}",
            'callback_data' => 'noop:none:0',
        ];

        if ($page < $totalPages) {
            $nextPage = $page + 1;
            $row[] = [
                'text' => ($locale === 'en' ? 'Next' : 'ወደፊት') . ' ▶️',
                'callback_data' => "{$prefix}:{$nextPage}",
            ];
        }

        return self::inlineKeyboard([$row]);
    }

    /**
     * Number parser tolerant of commas, spaces, and fractional numbers.
     * e.g. "1,500.50" -> 1500.5, "2 000" -> 2000.0, "5.25" -> 5.25
     *
     * @return float|null Returns parsed float, or null if invalid
     */
    public static function parseNumber(string $input): ?float
    {
        $cleaned = trim($input);

        // Normalize full-width spaces and regular spaces
        $cleaned = str_replace(["\xc2\xa0", ' '], '', $cleaned);

        // Remove thousand separator commas when followed by decimal point or digits
        // If format is like "1,500.50", strip comma
        if (str_contains($cleaned, '.') && str_contains($cleaned, ',')) {
            $cleaned = str_replace(',', '', $cleaned);
        } elseif (substr_count($cleaned, ',') === 1 && !str_contains($cleaned, '.')) {
            // If user typed e.g. "12,5" treating comma as decimal separator
            // check if there are 1-3 digits after comma
            $parts = explode(',', $cleaned);
            if (strlen($parts[1]) <= 3) {
                $cleaned = $parts[0] . '.' . $parts[1];
            } else {
                $cleaned = str_replace(',', '', $cleaned);
            }
        } else {
            $cleaned = str_replace(',', '', $cleaned);
        }

        if (!is_numeric($cleaned)) {
            return null;
        }

        return (float) $cleaned;
    }
}
