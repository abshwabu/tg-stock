<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Context\CurrentShopContext;
use App\Models\Location;
use App\Models\Movement;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\ActivityLogger;
use App\Services\LedgerService;
use App\Services\PermissionService;
use App\Services\Telegram\BotStateService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LocationHandler
{
    public function __construct(
        protected TelegramClient $telegram,
        protected PermissionService $permissionService,
        protected LedgerService $ledger,
        protected BotStateService $botState
    ) {}

    /**
     * Get the currently active location for a user.
     * Guaranteed to return an authorized location if available.
     */
    public function getCurrentLocation(?User $user): ?Location
    {
        if ($user === null || empty($user->shop_id)) {
            return null;
        }

        $shop = Shop::find($user->shop_id);
        if ($shop === null) {
            return null;
        }

        $allowedIds = $this->permissionService->getLocationIds($user);

        if (empty($allowedIds)) {
            return $shop->getDefaultLocation();
        }

        // If user is restricted to a single location, return that location
        if (count($allowedIds) === 1) {
            return Location::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shop->id)
                ->where('id', $allowedIds[0])
                ->first() ?? $shop->getDefaultLocation();
        }

        // Check cached selection
        $cacheKey = "user_cur_loc:{$user->id}";
        $selectedId = Cache::get($cacheKey);

        if ($selectedId && in_array((string) $selectedId, $allowedIds, true)) {
            $loc = Location::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shop->id)
                ->where('id', $selectedId)
                ->where('is_active', true)
                ->whereNull('deleted_at_ms')
                ->first();

            if ($loc !== null) {
                return $loc;
            }
        }

        // Fallback: pick default location if authorized, or first active allowed location
        $defaultLoc = $shop->getDefaultLocation();
        if (in_array((string) $defaultLoc->id, $allowedIds, true)) {
            Cache::put($cacheKey, (string) $defaultLoc->id, now()->addDays(30));
            return $defaultLoc;
        }

        $firstLoc = Location::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->whereIn('id', $allowedIds)
            ->where('is_active', true)
            ->whereNull('deleted_at_ms')
            ->first();

        if ($firstLoc !== null) {
            Cache::put($cacheKey, (string) $firstLoc->id, now()->addDays(30));
            return $firstLoc;
        }

        return $defaultLoc;
    }

    /**
     * Switch current active location for a user.
     */
    public function setCurrentLocation(User $user, string $locationId): bool
    {
        $allowedIds = $this->permissionService->getLocationIds($user);
        if (!in_array($locationId, $allowedIds, true)) {
            return false;
        }

        Cache::put("user_cur_loc:{$user->id}", $locationId, now()->addDays(30));
        return true;
    }

    /**
     * Get all active locations authorized for the user.
     *
     * @return Collection<int, Location>
     */
    public function getAllowedLocations(User $user): Collection
    {
        $allowedIds = $this->permissionService->getLocationIds($user);

        return Location::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->whereIn('id', $allowedIds)
            ->where('is_active', true)
            ->whereNull('deleted_at_ms')
            ->orderBy('is_default', 'desc')
            ->orderBy('name', 'asc')
            ->get();
    }

    /**
     * Display the Location Switcher inline menu (only for users with >1 location).
     */
    public function handleSwitchMenu(int|string $chatId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || empty($user->shop_id)) {
            return;
        }

        $locations = $this->getAllowedLocations($user);
        if ($locations->count() <= 1) {
            // Single-location users never see the switcher
            return;
        }

        $currentLoc = $this->getCurrentLocation($user);
        $rows = [];

        foreach ($locations as $loc) {
            $isCurrent = $currentLoc && $currentLoc->id === $loc->id;
            $icon = $isCurrent ? '🔘' : '⚪️';
            $kindIcon = $loc->kind === 'warehouse' ? '🏭 ' : '🏬 ';

            $rows[] = [
                [
                    'text' => "{$icon} {$kindIcon}{$loc->name}",
                    'callback_data' => "loc:set:{$loc->id}",
                ],
            ];
        }

        $text = $locale === 'en'
            ? "📍 <b>Switch Location</b>\nYour current location: <b>{$currentLoc?->name}</b>\nTap a location below to switch:"
            : "📍 <b>ቦታ ቀይር</b>\nየአሁን ቦታዎ: <b>{$currentLoc?->name}</b>\nለመቀየር ከታች ካሉት አንዱን ይምረጡ:";

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($rows),
        ]);
    }

    /**
     * Handle user selecting a location from switcher callback.
     */
    public function handleSelectLocation(string $cqId, int|string $chatId, int $messageId, string $locationId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null) {
            $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
            return;
        }

        $success = $this->setCurrentLocation($user, $locationId);
        if (!$success) {
            $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
            return;
        }

        $newLoc = Location::withoutGlobalScope(ShopScope::class)->find($locationId);
        $locName = $newLoc?->name ?? 'Location';

        $this->telegram->answerCallbackQuery(
            $cqId,
            $locale === 'en' ? "Switched to {$locName}" : "ወደ {$locName} ተቀይሯል"
        );

        // Send updated main menu with new location tag
        $msg = $locale === 'en'
            ? "📍 Active location changed to: <b>{$locName}</b>\nAll sales, stock-ins, and adjustments will now record at this location."
            : "📍 የአሁን የስራ ቦታ ተቀይሯል: <b>{$locName}</b>\nከአሁን በኋላ የሚመዘገቡ ሽያጮችና ገቢዎች በዚህ ቦታ ይመዘገባሉ።";

        $this->telegram->sendMessage($chatId, $msg, [
            'reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user),
        ]);
    }

    /**
     * Settings > Locations Management menu (Owner / locations.manage).
     */
    public function handleSettingsLocations(string $cqId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if (!$this->permissionService->can($user, 'locations.manage')) {
            $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);

        $shop = Shop::find($user->shop_id);
        $locations = Location::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->whereNull('deleted_at_ms')
            ->orderBy('is_default', 'desc')
            ->orderBy('name', 'asc')
            ->get();

        $rows = [];

        // 1. If only 1 location exists or locations not enabled: Offer "Enable Warehouse + Store"
        if ($locations->count() <= 1 || !$shop->locations_enabled) {
            $rows[] = [
                [
                    'text' => '🏬🏭 ' . ($locale === 'en' ? 'Enable "Warehouse + Store"' : '"መጋዘን + ሱቅ" አስጀምር'),
                    'callback_data' => 'loc:wh_enable:0',
                ],
            ];
        }

        // 2. Receipt confirmation toggle
        $receiptStatus = $shop->transfer_requires_receipt
            ? ($locale === 'en' ? 'Receipt Needed: [ ON ✅ ]' : 'ዝውውር መቀበያ: [ በርቷል ✅ ]')
            : ($locale === 'en' ? 'Receipt Needed: [ OFF ❌ (Instant) ]' : 'ዝውውር መቀበያ: [ ጠፍቷል ❌ (ወዲያውኑ) ]');

        $rows[] = [
            ['text' => '🔄 ' . $receiptStatus, 'callback_data' => 'loc:receipt_toggle:0'],
        ];

        // 3. List locations with rename and archive actions
        foreach ($locations as $loc) {
            $statusIcon = $loc->is_active ? '🟢' : '⏸';
            $kindIcon = $loc->kind === 'warehouse' ? '🏭' : '🏬';
            $defaultBadge = $loc->is_default ? ' (Default)' : '';

            $rows[] = [
                ['text' => "{$statusIcon} {$kindIcon} {$loc->name}{$defaultBadge}", 'callback_data' => "loc:view:{$loc->id}"],
            ];
        }

        $rows[] = [
            ['text' => '◀️ ' . ($locale === 'en' ? 'Back to Settings' : 'ወደ ቅንብሮች ተመለስ'), 'callback_data' => 'settings:menu:0'],
        ];

        $text = $locale === 'en'
            ? "🏢 <b>Location & Transfer Settings — {$shop->name}</b>\n• Active Locations: <b>{$locations->where('is_active', true)->count()}</b>\n• Transfer Mode: <b>" . ($shop->transfer_requires_receipt ? 'Requires Confirmation' : 'Instant') . "</b>\n\nManage your locations below:"
            : "🏢 <b>የቦታዎች እና ዝውውር ቅንብሮች — {$shop->name}</b>\n• ንቁ ቦታዎች: <b>{$locations->where('is_active', true)->count()}</b>\n• የዝውውር ሁኔታ: <b>" . ($shop->transfer_requires_receipt ? 'የተቀባይ ማረጋገጫ ያስፈልጋል' : 'ወዲያውኑ ይፈጸማል') . "</b>\n\nቦታዎችን ለማስተዳደር ከታች ይምረጡ:";

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($rows),
        ]);
    }

    /**
     * View a single location with Rename / Archive options.
     */
    public function handleViewLocation(string $cqId, int|string $chatId, int $messageId, string $locId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if (!$this->permissionService->can($user, 'locations.manage')) {
            $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);

        $loc = Location::withoutGlobalScope(ShopScope::class)->find($locId);
        if ($loc === null) {
            return;
        }

        $hasStock = $loc->hasStock();
        $stockStatus = $hasStock
            ? ($locale === 'en' ? 'Holds inventory stock' : 'ዕቃዎች በውስጡ ይገኛሉ')
            : ($locale === 'en' ? 'Empty (No stock)' : 'ባዶ (ዕቃ የለውም)');

        $text = "🏢 <b>Location: {$loc->name}</b>\n"
            . "• Type: <b>" . ucfirst($loc->kind) . "</b>\n"
            . "• Default: <b>" . ($loc->is_default ? 'Yes' : 'No') . "</b>\n"
            . "• Active: <b>" . ($loc->is_active ? 'Yes 🟢' : 'No ⏸') . "</b>\n"
            . "• Inventory: <b>{$stockStatus}</b>";

        $buttons = [];

        // Rename button
        $buttons[] = [
            ['text' => '✏️ ' . ($locale === 'en' ? 'Rename Location' : 'የቦታ ስም ቀይር'), 'callback_data' => "loc:rename:start:{$loc->id}"],
        ];

        // Archive / Deactivate button
        if (!$loc->is_default && $loc->is_active) {
            $buttons[] = [
                ['text' => '📦 ' . ($locale === 'en' ? 'Archive Location' : 'ቦታውን አቁም/መዝግብ'), 'callback_data' => "loc:archive:{$loc->id}"],
            ];
        }

        $buttons[] = [
            ['text' => '◀️ ' . ($locale === 'en' ? 'Back' : 'ተመለስ'), 'callback_data' => 'loc:settings:0'],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Start prompt to rename a location.
     */
    public function startRenameLocation(string $cqId, int|string $chatId, int $messageId, string $locId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if (!$this->permissionService->can($user, 'locations.manage')) {
            $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
            return;
        }

        $loc = Location::withoutGlobalScope(ShopScope::class)->find($locId);
        if ($loc === null) {
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);

        $this->botState->start($chatId, 'loc_rename', 'await_name', [
            'location_id' => $loc->id,
            'old_name' => $loc->name,
        ]);

        $prompt = $locale === 'en'
            ? "✏️ <b>Rename Location</b>\nEnter new name for <b>{$loc->name}</b>:"
            : "✏️ <b>የቦታ ስም መቀየር</b>\nለ <b>{$loc->name}</b> አዲሱን ስም ያስገቡ:";

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => TelegramUi::cancelKeyboard($locale),
        ]);
    }

    /**
     * Handle entering a new name for a location.
     */
    public function handleRenameEntered(int|string $chatId, string $newName, array $data, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if (!$this->permissionService->can($user, 'locations.manage')) {
            $this->botState->clear($chatId);
            $this->telegram->sendMessage($chatId, __('permission_denied'));
            return;
        }

        $trimmed = trim($newName);
        if (mb_strlen($trimmed) < 2) {
            $this->telegram->sendMessage($chatId, $locale === 'en' ? 'Name must be at least 2 characters.' : 'ስም ቢያንስ 2 ፊደላት መሆን አለበት።');
            return;
        }

        $locId = (string) ($data['location_id'] ?? '');
        $loc = Location::withoutGlobalScope(ShopScope::class)->find($locId);
        if ($loc === null) {
            $this->botState->clear($chatId);
            $this->telegram->sendMessage($chatId, 'Location not found.');
            return;
        }

        $oldName = $loc->name;
        $loc->name = $trimmed;
        $loc->updated_at_ms = (int) (microtime(true) * 1000);
        $loc->save();

        ActivityLogger::log(
            (string) $user->shop_id,
            $user->id,
            'location.rename',
            "Renamed location from '{$oldName}' to '{$trimmed}'"
        );

        $this->botState->clear($chatId);

        $msg = $locale === 'en'
            ? "✅ Location renamed to <b>{$trimmed}</b>!"
            : "✅ የቦታው ስም ወደ <b>{$trimmed}</b> ተቀይሯል!";

        $this->telegram->sendMessage($chatId, $msg, [
            'reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user),
        ]);

        $this->handleSettingsLocations('0', $chatId, 0, $user);
    }

    /**
     * Start the "Warehouse + Store" setup wizard.
     */
    public function handleEnableWarehouseWizard(string $cqId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);

        $shop = Shop::find($user->shop_id);

        // Ensure "Store" and "Warehouse" locations exist
        $defaultLoc = $shop->getDefaultLocation();
        $defaultLoc->name = 'Store';
        $defaultLoc->kind = 'store';
        $defaultLoc->is_default = true;
        $defaultLoc->is_active = true;
        $defaultLoc->save();

        $warehouse = Location::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->where('kind', 'warehouse')
            ->first();

        if ($warehouse === null) {
            $warehouse = Location::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $shop->id,
                'name' => 'Warehouse',
                'kind' => 'warehouse',
                'is_default' => false,
                'is_active' => true,
            ]);
        }

        // Ask where existing stock lives
        $text = $locale === 'en'
            ? "🏢 <b>Warehouse + Store Setup</b>\n\nWe have created <b>Store</b> and <b>Warehouse</b>.\n\n📍 <b>Where does your existing inventory stock currently live?</b>"
            : "🏢 <b>የመጋዘን እና ሱቅ ማዋቀር</b>\n\n<b>ሱቅ (Store)</b> እና <b>መጋዘን (Warehouse)</b> ተፈጥረዋል።\n\n📍 <b>አሁን ያለው የሱቁ ነባር ዕቃ የት ነው የሚገኘው?</b>";

        $buttons = TelegramUi::inlineKeyboard([
            [
                ['text' => '🏬 Store (ሱቅ)', 'callback_data' => "loc:stock_live:{$defaultLoc->id}"],
                ['text' => '🏭 Warehouse (መጋዘን)', 'callback_data' => "loc:stock_live:{$warehouse->id}"],
            ],
            [
                ['text' => '◀️ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'loc:settings:0'],
            ],
        ]);

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => $buttons,
        ]);
    }

    /**
     * Finish "Warehouse + Store" setup by assigning existing stock.
     */
    public function handleAssignExistingStock(string $cqId, int|string $chatId, int $messageId, string $targetLocId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);

        $shop = Shop::find($user->shop_id);
        $targetLoc = Location::withoutGlobalScope(ShopScope::class)->find($targetLocId);

        if ($targetLoc === null) {
            return;
        }

        DB::transaction(function () use ($shop, $targetLocId): void {
            // Assign all unassigned or legacy movements to chosen location
            Movement::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shop->id)
                ->whereNull('location_id')
                ->update(['location_id' => $targetLocId]);

            $shop->locations_enabled = true;
            $shop->save();
        });

        ActivityLogger::log(
            (string) $shop->id,
            $user->id,
            'locations.enabled',
            "Enabled Warehouse + Store. Assigned existing stock to {$targetLoc->name}."
        );

        $text = $locale === 'en'
            ? "✅ <b>Warehouse + Store Enabled!</b>\n\nExisting stock has been assigned to <b>{$targetLoc->name}</b>.\nYou can now initiate inventory transfers and switch active locations."
            : "✅ <b>መጋዘን እና ሱቅ በተሳካ ሁኔታ ተጀምሯል!</b>\n\nነባር ዕቃዎች በሙሉ ወደ <b>{$targetLoc->name}</b> ተመድበዋል። አሁን ዕቃዎችን ማስተላለፍና የሥራ ቦታዎችን መቀያየር ይችላሉ።";

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard([
                [['text' => '◀️ ' . ($locale === 'en' ? 'Back to Settings' : 'ወደ ቅንብሮች'), 'callback_data' => 'settings:menu:0']],
            ]),
        ]);
    }

    /**
     * Toggle "Transfers need receipt confirmation".
     */
    public function handleToggleReceipt(string $cqId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if (!$this->permissionService->can($user, 'locations.manage')) {
            $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
            return;
        }

        $shop = Shop::find($user->shop_id);
        if ($shop === null) {
            return;
        }

        $shop->transfer_requires_receipt = !$shop->transfer_requires_receipt;
        $shop->save();

        $statusMsg = $shop->transfer_requires_receipt
            ? ($locale === 'en' ? 'Transfers now require receiver confirmation.' : 'ዝውውሮች አሁን የተረካቢ ማረጋገጫ ያስፈልጋቸዋል።')
            : ($locale === 'en' ? 'Transfers are now instant.' : 'ዝውውሮች አሁን ወዲያውኑ ይፈጸማሉ።');

        $this->telegram->answerCallbackQuery($cqId, $statusMsg, showAlert: true);

        $this->handleSettingsLocations($cqId, $chatId, $messageId, $user);
    }

    /**
     * Archive location with guard checks.
     */
    public function handleArchiveLocation(string $cqId, int|string $chatId, int $messageId, string $locId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if (!$this->permissionService->can($user, 'locations.manage')) {
            $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
            return;
        }

        $loc = Location::withoutGlobalScope(ShopScope::class)->find($locId);
        if ($loc === null) {
            return;
        }

        $reason = null;
        if (!$loc->canArchive($reason)) {
            $this->telegram->answerCallbackQuery($cqId, "⚠️ {$reason}", showAlert: true);
            return;
        }

        $loc->is_active = false;
        $loc->deleted_at_ms = (int) (microtime(true) * 1000);
        $loc->save();

        ActivityLogger::log(
            (string) $user->shop_id,
            $user->id,
            'location.archive',
            "Archived location {$loc->name}."
        );

        $this->telegram->answerCallbackQuery(
            $cqId,
            $locale === 'en' ? "Location archived." : "ቦታው ተዘግቷል።",
            showAlert: true
        );

        $this->handleSettingsLocations($cqId, $chatId, $messageId, $user);
    }
}
