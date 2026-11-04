<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Context\CurrentShopContext;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\Shop;
use App\Models\TeamInvite;
use App\Models\User;
use App\Models\UserPermission;
use App\Scopes\ShopScope;
use App\Services\ActivityLogger;
use App\Services\PermissionService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use App\Support\EthiopicCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TeamHandler
{
    public const FEATURES = [
        'cost.view' => ['en' => 'Can see cost prices', 'am' => 'የግዢ ዋጋ ማየት'],
        'price.edit' => ['en' => 'Can edit selling prices', 'am' => 'የመሸጫ ዋጋ መቀየር'],
        'stock.in' => ['en' => 'Can stock in', 'am' => 'ዕቃ ማስገባት'],
        'sale.create' => ['en' => 'Can record sales', 'am' => 'ሽያጭ መመዝገብ'],
        'stock.adjust' => ['en' => 'Can adjust stock', 'am' => 'ስቶክ ማስተካከል'],
        'transfer.create' => ['en' => 'Can create transfers', 'am' => 'ዕቃ ማስተላለፍ'],
        'transfer.receive' => ['en' => 'Can receive transfers', 'am' => 'የተላለፈ ዕቃ መቀበል'],
        'products.view' => ['en' => 'Can view products', 'am' => 'ዕቃዎችን ማየት'],
        'products.create' => ['en' => 'Can add new products', 'am' => 'አዲስ ዕቃ መመዝገብ'],
        'products.edit' => ['en' => 'Can edit products', 'am' => 'ዕቃዎችን ማስተካከል'],
        'products.delete' => ['en' => 'Can delete products', 'am' => 'ዕቃዎችን መሰረዝ'],
        'reports.view' => ['en' => 'Can view all reports', 'am' => 'ሁሉንም ሪፖርቶች ማየት'],
        'reports.own_sales' => ['en' => 'Can view own sales', 'am' => 'የራስን ሽያጭ ሪፖርት ማየት'],
        'export' => ['en' => 'Can export data', 'am' => 'መረጃ ኤክስፖርት ማድረግ'],
        'team.manage' => ['en' => 'Can manage team', 'am' => 'ቡድንን ማስተዳደር'],
        'locations.manage' => ['en' => 'Can manage locations', 'am' => 'ቦታዎችን ማስተዳደር'],
        'undo.own' => ['en' => 'Can undo own actions', 'am' => 'የራስን ተግባር መመለስ'],
        'sale.dube' => ['en' => 'Can sell on credit (dube)', 'am' => 'በዱቤ መሸጥ (ዱቤ መመዝገብ)'],
        'payment.collect' => ['en' => 'Can receive dube payments', 'am' => 'የዱቤ ክፍያ መቀበል'],
        'customers.manage' => ['en' => 'Can add customers', 'am' => 'ደንበኞችን መጨመር'],
        'dube.view' => ['en' => 'Can see who owes money', 'am' => 'ዕዳ ያለባቸውን ማየት'],
    ];

    public function __construct(
        protected TelegramClient $telegram,
        protected PermissionService $permissionService
    ) {}

    /**
     * Show team management menu (Requires team.manage).
     */
    public function handleTeamMenu(int|string $chatId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || empty($user->shop_id) || !$this->permissionService->can($user, 'team.manage')) {
            $this->telegram->sendMessage($chatId, __('permission_denied'));
            return;
        }

        $shop = Shop::find($user->shop_id);
        $shopId = (string) $user->shop_id;

        $members = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->orderBy('name', 'asc')
            ->get();

        $rows = [];
        foreach ($members as $m) {
            $statusIcon = $m->is_active ? '🟢' : '⏸';
            $roleLabel = ucfirst(str_replace('_', ' ', $m->role));
            $isMe = $m->id === $user->id ? ' (You)' : '';
            $rows[] = [
                ['text' => "{$statusIcon} {$m->name} [{$roleLabel}]{$isMe}", 'callback_data' => "team:view:{$m->id}"],
            ];
        }

        $adjustSetting = ($shop?->staff_can_adjust) ? '✅ Enabled' : '❌ Disabled';
        $toggleAdjustLabel = ($shop?->staff_can_adjust)
            ? '🔧 Staff Adjust: [ Allowed ✅ ]'
            : '🔧 Staff Adjust: [ Disallowed ❌ ]';

        $rows[] = [
            ['text' => '➕ ' . ($locale === 'en' ? 'Invite Staff' : 'አዲስ ሠራተኛ ጋብዝ'), 'callback_data' => 'team:invite:create'],
        ];

        if ($user->role === 'owner') {
            $rows[] = [
                ['text' => $toggleAdjustLabel, 'callback_data' => 'team:toggle:adjust'],
            ];
        }

        $rows[] = [
            ['text' => '📜 ' . ($locale === 'en' ? 'Activity Log (Last 30)' : 'የእንቅስቃሴ መዝገብ'), 'callback_data' => 'team:log:view'],
        ];

        $text = $locale === 'en'
            ? "👥 <b>Team Management — {$shop->name}</b>\n• Team Members: <b>{$members->count()}</b>\n• Staff May Adjust: <b>{$adjustSetting}</b>\nTap any member below to manage permissions:"
            : "👥 <b>የቡድን አስተዳደር — {$shop->name}</b>\n• የቡድን አባላት ብዛት: <b>{$members->count()}</b>\n• ሠራተኞች ስቶክ ማስተካከል: <b>{$adjustSetting}</b>\nአባል ለማስተዳደር ከታች ይምረጡ:";

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($rows),
        ]);
    }

    /**
     * Generate a new single-use, 48-hour staff invite link.
     */
    public function createInviteLink(string $callbackId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'team.manage')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $token = bin2hex(random_bytes(16));
        $tokenHash = hash('sha256', $token);

        $invite = TeamInvite::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $user->shop_id,
            'inviter_user_id' => $user->id,
            'token_hash' => $tokenHash,
            'role' => 'staff',
            'expires_at' => now()->addHours(48),
        ]);

        ActivityLogger::log(
            (string) $user->shop_id,
            $user->id,
            'team.invite_created',
            "Generated staff invite link expiring in 48 hours."
        );

        $botUsername = config('services.telegram.bot_username', 'InventoryEtBot');
        $inviteLink = "https://t.me/{$botUsername}?start=join_{$token}";

        $text = $locale === 'en'
            ? "🔗 <b>Staff Invite Link Generated!</b>\n\nShare this link with your cashier or staff member:\n<code>{$inviteLink}</code>\n\n⏳ <i>Expires in 48 hours. Single-use only.</i>"
            : "🔗 <b>የሠራተኛ መጋበዣ ሊንክ ተዘጋጅቷል!</b>\n\nይህን ሊንክ ለካሺየርዎ ወይም ለሠራተኛዎ ይላኩ:\n<code>{$inviteLink}</code>\n\n⏳ <i>የሚያገለግለው ለ48 ሰዓት ብቻ ሲሆን ለአንድ ሰው ብቻ ይሰራል።</i>";

        $buttons = TelegramUi::inlineKeyboard([
            [
                ['text' => '◀️ ' . ($locale === 'en' ? 'Back to Team' : 'ወደ ቡድን ተመለስ'), 'callback_data' => 'team:menu:0'],
            ],
        ]);

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => $buttons,
        ]);
    }

    /**
     * Handle someone clicking the invite link: /start join_<token>
     */
    public function handleJoinInvite(int|string $chatId, array $from, string $token): void
    {
        $tokenHash = hash('sha256', trim($token));
        $invite = TeamInvite::withoutGlobalScope(ShopScope::class)
            ->where('token_hash', $tokenHash)
            ->first();

        if ($invite === null || $invite->isExpired() || $invite->isUsed()) {
            $this->telegram->sendMessage(
                $chatId,
                "❌ ይህ የመጋበዣ ሊንክ ልክ ያልሆነ ወይም ጊዜው ያለፈበት ነው / Invalid or expired invite link."
            );
            return;
        }

        $shop = Shop::find($invite->shop_id);
        if ($shop === null) {
            return;
        }

        $userName = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
        if (empty($userName)) {
            $userName = $from['username'] ?? 'Staff Member';
        }

        // Attach user to this shop with staff role
        CurrentShopContext::runWith($shop, function () use ($shop, $chatId, $userName): void {
            User::withoutGlobalScope(ShopScope::class)->updateOrCreate(
                ['telegram_id' => (int) $chatId],
                [
                    'id' => (string) Str::uuid7(),
                    'name' => $userName,
                    'language' => $shop->language,
                    'shop_id' => $shop->id,
                    'role' => 'staff',
                    'is_active' => true,
                ]
            );
        });

        // Mark invite used immediately
        $invite->used_at = now();
        $invite->used_by_telegram_id = (int) $chatId;
        $invite->save();

        ActivityLogger::log(
            $shop->id,
            null,
            'team.join',
            "{$userName} (Telegram ID: {$chatId}) joined shop team as staff."
        );

        $locale = $shop->language ?? 'am';
        app()->setLocale($locale);

        $welcome = $locale === 'en'
            ? "🎉 Welcome to <b>{$shop->name}</b>! You are now connected as Staff."
            : "🎉 እንኳን ወደ <b>{$shop->name}</b> በደህና መጡ! አሁን እንደ ሠራተኛ ተቀላቅለዋል።";

        $this->telegram->sendMessage($chatId, $welcome, [
            'reply_markup' => TelegramUi::mainMenuKeyboard($locale),
        ]);

        // Notify shop owner
        $owner = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->where('role', 'owner')
            ->first();

        if ($owner && $owner->telegram_id) {
            $notifyOwner = "👤 <b>{$userName}</b> joined your shop <b>{$shop->name}</b> as Staff!";
            $this->telegram->sendMessage($owner->telegram_id, $notifyOwner);
        }
    }

    /**
     * View a single member card with management actions and Permission Editor.
     */
    public function viewStaffMember(string $callbackId, int|string $chatId, int $messageId, string $staffUserId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'team.manage')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $staff = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $staffUserId)
            ->first();

        if ($staff === null) {
            $this->telegram->sendMessage($chatId, 'Staff member not found.');
            return;
        }

        $statusStr = $staff->is_active ? '🟢 Active' : '⏸ Inactive';
        $toggleLabel = $staff->is_active
            ? '⏸ ' . ($locale === 'en' ? 'Deactivate' : 'አግድ')
            : '▶️ ' . ($locale === 'en' ? 'Activate' : 'ፍቀድ');

        $roleLabel = ucfirst(str_replace('_', ' ', $staff->role));
        $allowedLocs = $this->permissionService->getLocationIds($staff);
        $locCountStr = empty($allowedLocs)
            ? ($locale === 'en' ? 'All Locations' : 'ሁሉም ቦታዎች')
            : count($allowedLocs) . ' ' . ($locale === 'en' ? 'locations' : 'ቦታዎች');

        $text = "👤 <b>Member: {$staff->name}</b>\n"
            . "• Role: <b>{$roleLabel}</b>\n"
            . "• Status: <b>{$statusStr}</b>\n"
            . "• Locations Allowed: <b>{$locCountStr}</b>\n"
            . "• Joined: " . ($staff->created_at?->format('M j, Y') ?? '—');

        $buttons = [];

        // 1. Permission Editor button
        $buttons[] = [
            ['text' => '⚙️ ' . ($locale === 'en' ? 'Edit Permissions & Role' : 'ፈቃዶችና ሚና አስተካክል'), 'callback_data' => "team:ped_start:{$staff->id}"],
        ];

        // 2. Status toggle and remove (cannot remove last owner)
        $buttons[] = [
            ['text' => $toggleLabel, 'callback_data' => "team:toggle_active:{$staff->id}"],
            ['text' => '🗑 ' . ($locale === 'en' ? 'Remove' : 'አስወግድ'), 'callback_data' => "team:remove:{$staff->id}"],
        ];

        // 3. Transfer ownership if user is owner and staff is not already owner
        if ($user->role === 'owner' && $staff->id !== $user->id) {
            $buttons[] = [
                ['text' => '👑 ' . ($locale === 'en' ? 'Transfer Ownership' : 'የባለቤትነት ማስተላለፍ'), 'callback_data' => "team:transfer_init:{$staff->id}"],
            ];
        }

        $buttons[] = [
            ['text' => '◀️ ' . ($locale === 'en' ? 'Back' : 'ተመለስ'), 'callback_data' => 'team:menu:0'],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Start Permission Editor: Step 1 (Role Presets).
     */
    public function startPermissionEditor(string $callbackId, int|string $chatId, int $messageId, string $targetUserId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'team.manage')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $target = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $targetUserId)
            ->first();

        if ($target === null) {
            $this->telegram->sendMessage($chatId, 'User not found.');
            return;
        }

        // Initialize editor session
        $overrides = UserPermission::where('user_id', $target->id)->pluck('effect', 'permission')->all();
        $allowedLocs = $this->permissionService->getLocationIds($target);

        $sessionData = [
            'target_user_id' => $target->id,
            'role' => $target->role,
            'overrides' => $overrides,
            'location_ids' => $allowedLocs,
        ];

        Cache::put("ped_session:{$user->id}:{$target->id}", $sessionData, now()->addHours(1));

        $this->renderRolePresets($chatId, $messageId, $sessionData, $user, $locale);
    }

    /**
     * Step 1: Render Role Presets.
     */
    public function renderRolePresets(int|string $chatId, int $messageId, array $session, ?User $user, string $locale): void
    {
        $targetId = $session['target_user_id'];
        $curRole = $session['role'];

        $roles = [
            'owner' => ['icon' => '👑', 'en' => 'Owner (All Rights)', 'am' => 'ባለቤት (ሙሉ ፈቃድ)'],
            'salesperson' => ['icon' => '💼', 'en' => 'Salesperson', 'am' => 'የሽያጭ ሠራተኛ'],
            'warehouse_keeper' => ['icon' => '🏭', 'en' => 'Warehouse Keeper', 'am' => 'የመጋዘን ኃላፊ'],
            'custom' => ['icon' => '🛠', 'en' => 'Custom Role', 'am' => 'ልዩ የተበጀ ሚና'],
        ];

        $buttons = [];
        foreach ($roles as $roleKey => $roleInfo) {
            $isSelected = $curRole === $roleKey;
            $mark = $isSelected ? ' ✓' : '';
            $label = "{$roleInfo['icon']} {$roleInfo[$locale]}{$mark}";
            $buttons[] = [
                ['text' => $label, 'callback_data' => "team:ped_role:{$targetId}:{$roleKey}"],
            ];
        }

        $buttons[] = [
            ['text' => '➡️ ' . ($locale === 'en' ? 'Feature Toggles' : 'የተግባራት ፈቃዶች') . ' ➡️', 'callback_data' => "team:ped_togs:{$targetId}:1"],
        ];
        $buttons[] = [
            ['text' => '◀️ ' . ($locale === 'en' ? 'Back' : 'ተመለስ'), 'callback_data' => "team:view:{$targetId}"],
        ];

        $target = User::withoutGlobalScope(ShopScope::class)->find($targetId);
        $name = $target?->name ?? 'Member';

        $text = $locale === 'en'
            ? "👥 <b>Permission Editor — Step 1/3: Role Presets</b>\nMember: <b>{$name}</b>\n\nSelect a preset role. Each preset configures standard permissions, which you can customize in the next step:"
            : "👥 <b>የፈቃድ ማስተካከያ — ደረጃ 1/3: የሚና ምርጫ</b>\nአባል: <b>{$name}</b>\n\nከታች ከተዘረዘሩት አንዱን ሚና ይምረጡ። በሚቀጥለው ደረጃ እያንዳንዱን ፈቃድ ለየብቻ ማብራት/ማጥፋት ይችላሉ:";

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Handle selecting a preset role.
     */
    public function selectPresetRole(string $callbackId, int|string $chatId, int $messageId, string $targetId, string $role, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'team.manage')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $cacheKey = "ped_session:{$user->id}:{$targetId}";
        $session = Cache::get($cacheKey);
        if (!$session) {
            $this->telegram->answerCallbackQuery($callbackId, 'Session expired.');
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $session['role'] = $role;
        // Reset overrides to clean slate for presets
        if (in_array($role, ['owner', 'salesperson', 'warehouse_keeper'], true)) {
            $session['overrides'] = [];
        }

        Cache::put($cacheKey, $session, now()->addHours(1));

        $this->renderRolePresets($chatId, $messageId, $session, $user, $locale);
    }

    /**
     * Step 2: Render paginated list of feature toggles.
     */
    public function viewFeatureToggles(string $callbackId, int|string $chatId, int $messageId, string $targetId, int $page, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'team.manage')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $cacheKey = "ped_session:{$user->id}:{$targetId}";
        $session = Cache::get($cacheKey);
        if (!$session) {
            $this->startPermissionEditor($callbackId, $chatId, $messageId, $targetId, $user);
            return;
        }

        $features = self::FEATURES;
        $keys = array_keys($features);
        $totalItems = count($keys);
        $perPage = 5;
        $totalPages = (int) ceil($totalItems / $perPage);
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;
        $currentKeys = array_slice($keys, $offset, $perPage);

        $role = $session['role'];
        $overrides = $session['overrides'] ?? [];

        $buttons = [];
        foreach ($currentKeys as $permKey) {
            $allowed = $this->isFeatureAllowedInSession($role, $permKey, $overrides);
            $icon = $allowed ? '✅' : '⛔';
            $label = "{$icon} " . ($features[$permKey][$locale] ?? $permKey);

            $buttons[] = [
                ['text' => $label, 'callback_data' => "team:ped_tog:{$targetId}:{$permKey}:{$page}"],
            ];
        }

        // Pagination row
        $navRow = [];
        if ($page > 1) {
            $prev = $page - 1;
            $navRow[] = ['text' => '◀️ ' . ($locale === 'en' ? 'Prev' : 'ወደኋላ'), 'callback_data' => "team:ped_togs:{$targetId}:{$prev}"];
        }
        $navRow[] = ['text' => "{$page} / {$totalPages}", 'callback_data' => 'noop:none:0'];
        if ($page < $totalPages) {
            $next = $page + 1;
            $navRow[] = ['text' => ($locale === 'en' ? 'Next' : 'ወደፊት') . ' ▶️', 'callback_data' => "team:ped_togs:{$targetId}:{$next}"];
        }
        $buttons[] = $navRow;

        // Next step & Preset back
        $buttons[] = [
            ['text' => '📍 ' . ($locale === 'en' ? 'Location Access' : 'የቦታ ፈቃዶች') . ' ➡️', 'callback_data' => "team:ped_locs:{$targetId}:0"],
        ];
        $buttons[] = [
            ['text' => '👑 ' . ($locale === 'en' ? 'Role Presets' : 'የሚና ምርጫ'), 'callback_data' => "team:ped_start:{$targetId}"],
        ];

        $target = User::withoutGlobalScope(ShopScope::class)->find($targetId);
        $name = $target?->name ?? 'Member';

        $text = $locale === 'en'
            ? "👥 <b>Permission Editor — Step 2/3: Feature Toggles (Page {$page}/{$totalPages})</b>\nMember: <b>{$name}</b> | Role: <b>{$role}</b>\n\nTap any feature below to toggle (✅ Allowed / ⛔ Denied):"
            : "👥 <b>የፈቃድ ማስተካከያ — ደረጃ 2/3: የተግባራት ፈቃዶች (ገጽ {$page}/{$totalPages})</b>\nአባል: <b>{$name}</b> | ሚና: <b>{$role}</b>\n\nፈቃዱን ለማብራት/ለማጥፋት ይጫኑ (✅ የተፈቀደ / ⛔ የተከለከለ):";

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Handle flipping a feature toggle.
     */
    public function toggleFeature(string $callbackId, int|string $chatId, int $messageId, string $targetId, string $permKey, int $page, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'team.manage')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $cacheKey = "ped_session:{$user->id}:{$targetId}";
        $session = Cache::get($cacheKey);
        if (!$session) {
            $this->telegram->answerCallbackQuery($callbackId, 'Session expired.');
            return;
        }

        $role = $session['role'];
        $overrides = $session['overrides'] ?? [];

        $currentlyAllowed = $this->isFeatureAllowedInSession($role, $permKey, $overrides);
        $newEffect = $currentlyAllowed ? 'deny' : 'allow';

        $session['overrides'][$permKey] = $newEffect;
        Cache::put($cacheKey, $session, now()->addHours(1));

        $this->telegram->answerCallbackQuery(
            $callbackId,
            $newEffect === 'allow' ? 'Allowed ✅' : 'Denied ⛔'
        );

        $this->viewFeatureToggles($callbackId, $chatId, $messageId, $targetId, $page, $user);
    }

    /**
     * Step 3: Location Access toggles.
     */
    public function viewLocationAccess(string $callbackId, int|string $chatId, int $messageId, string $targetId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'team.manage')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $cacheKey = "ped_session:{$user->id}:{$targetId}";
        $session = Cache::get($cacheKey);
        if (!$session) {
            $this->startPermissionEditor($callbackId, $chatId, $messageId, $targetId, $user);
            return;
        }

        $locations = Location::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('is_active', true)
            ->whereNull('deleted_at_ms')
            ->orderBy('is_default', 'desc')
            ->orderBy('name', 'asc')
            ->get();

        $allowedLocIds = $session['location_ids'] ?? [];

        $buttons = [];
        foreach ($locations as $loc) {
            // If allowedLocIds is empty, user currently has access to all locations
            $isAllowed = empty($allowedLocIds) || in_array((string) $loc->id, $allowedLocIds, true);
            $icon = $isAllowed ? '✅' : '⛔';
            $kindIcon = $loc->kind === 'warehouse' ? '🏭' : '🏬';
            $label = "{$icon} {$kindIcon} {$loc->name}";

            $buttons[] = [
                ['text' => $label, 'callback_data' => "team:ped_tloc:{$targetId}:{$loc->id}"],
            ];
        }

        $buttons[] = [
            ['text' => '📋 ' . ($locale === 'en' ? 'Summary & Save' : 'ማጠቃለያ እና አስቀምጥ') . ' ➡️', 'callback_data' => "team:ped_sum:{$targetId}:0"],
        ];
        $buttons[] = [
            ['text' => '◀️ ' . ($locale === 'en' ? 'Back to Features' : 'ወደ ፈቃዶች ተመለስ'), 'callback_data' => "team:ped_togs:{$targetId}:1"],
        ];

        $target = User::withoutGlobalScope(ShopScope::class)->find($targetId);
        $name = $target?->name ?? 'Member';

        $text = $locale === 'en'
            ? "👥 <b>Permission Editor — Step 3/3: Location Access</b>\nMember: <b>{$name}</b>\n\nChoose which shop locations this member may access and operate in (✅ Allowed / ⛔ Denied):"
            : "👥 <b>የፈቃድ ማስተካከያ — ደረጃ 3/3: የቦታዎች ፈቃድ</b>\nአባል: <b>{$name}</b>\n\nይህ አባል የትኞቹን ቦታዎች እንዲጠቀምና እንዲያይ ይፈቀድለታል (✅ የተፈቀደ / ⛔ የተከለከለ):";

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Handle flipping a location toggle.
     */
    public function toggleLocationAccess(string $callbackId, int|string $chatId, int $messageId, string $targetId, string $locId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'team.manage')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $cacheKey = "ped_session:{$user->id}:{$targetId}";
        $session = Cache::get($cacheKey);
        if (!$session) {
            $this->telegram->answerCallbackQuery($callbackId, 'Session expired.');
            return;
        }

        $allLocations = Location::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('is_active', true)
            ->whereNull('deleted_at_ms')
            ->pluck('id')
            ->map(fn($id) => (string) $id)
            ->all();

        $allowedLocIds = $session['location_ids'] ?? [];

        // If currently empty (all locations allowed), initialize to all locations first
        if (empty($allowedLocIds)) {
            $allowedLocIds = $allLocations;
        }

        if (in_array($locId, $allowedLocIds, true)) {
            $allowedLocIds = array_values(array_filter($allowedLocIds, fn($id) => $id !== $locId));
        } else {
            $allowedLocIds[] = $locId;
        }

        $session['location_ids'] = $allowedLocIds;
        Cache::put($cacheKey, $session, now()->addHours(1));

        $this->telegram->answerCallbackQuery($callbackId);
        $this->viewLocationAccess($callbackId, $chatId, $messageId, $targetId, $user);
    }

    /**
     * Step 4: Summary and Save.
     */
    public function viewPermissionSummary(string $callbackId, int|string $chatId, int $messageId, string $targetId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'team.manage')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $cacheKey = "ped_session:{$user->id}:{$targetId}";
        $session = Cache::get($cacheKey);
        if (!$session) {
            $this->startPermissionEditor($callbackId, $chatId, $messageId, $targetId, $user);
            return;
        }

        $target = User::withoutGlobalScope(ShopScope::class)->find($targetId);
        $name = $target?->name ?? 'Member';
        $role = ucfirst(str_replace('_', ' ', $session['role']));

        // Compute enabled features
        $enabled = [];
        foreach (self::FEATURES as $permKey => $labels) {
            if ($this->isFeatureAllowedInSession($session['role'], $permKey, $session['overrides'] ?? [])) {
                $enabled[] = $labels[$locale] ?? $permKey;
            }
        }

        $enabledStr = empty($enabled)
            ? '— ' . ($locale === 'en' ? 'None' : 'ምንም የለም')
            : implode(', ', array_slice($enabled, 0, 8)) . (count($enabled) > 8 ? " + " . (count($enabled) - 8) . " more" : "");

        // Compute locations
        $allowedLocIds = $session['location_ids'] ?? [];
        if (empty($allowedLocIds)) {
            $locStr = $locale === 'en' ? 'All shop locations' : 'ሁሉም የሱቅ ቦታዎች';
        } else {
            $locNames = Location::withoutGlobalScope(ShopScope::class)
                ->whereIn('id', $allowedLocIds)
                ->pluck('name')
                ->all();
            $locStr = implode(', ', $locNames);
        }

        if ($locale === 'en') {
            $text = "📋 <b>Permission Summary & Confirmation</b>\n\n"
                . "👤 Member: <b>{$name}</b>\n"
                . "👑 Role: <b>{$role}</b>\n"
                . "📍 Locations: <b>{$locStr}</b>\n\n"
                . "<b>Active Capabilities:</b>\n<i>{$enabledStr}</i>\n\n"
                . "⚠️ Saving will immediately update this member's permissions and send them a notification message on Telegram.";
        } else {
            $text = "📋 <b>የፈቃድ ማጠቃለያና ማረጋገጫ</b>\n\n"
                . "👤 አባል: <b>{$name}</b>\n"
                . "👑 ሚና: <b>{$role}</b>\n"
                . "📍 የተፈቀዱ ቦታዎች: <b>{$locStr}</b>\n\n"
                . "<b>ንቁ ፈቃዶች:</b>\n<i>{$enabledStr}</i>\n\n"
                . "⚠️ ሲያስቀምጡ የዚህ አባል ፈቃዶች ወዲያውኑ የሚተገበሩ ሲሆን በቴሌግራም መልዕክት ይደርሳቸዋል።";
        }

        $buttons = [
            [
                ['text' => '💾 ' . ($locale === 'en' ? 'Save & Notify Member' : 'አስቀምጥና አሳውቅ'), 'callback_data' => "team:ped_save:{$targetId}:0"],
            ],
            [
                ['text' => '✏️ ' . ($locale === 'en' ? 'Edit Again' : 'እንደገና አርትዕ'), 'callback_data' => "team:ped_start:{$targetId}"],
                ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => "team:view:{$targetId}"],
            ],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Step 5: Save permissions and notify the member.
     */
    public function savePermissions(string $callbackId, int|string $chatId, int $messageId, string $targetId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'team.manage')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $cacheKey = "ped_session:{$user->id}:{$targetId}";
        $session = Cache::get($cacheKey);
        if (!$session) {
            $this->telegram->answerCallbackQuery($callbackId, 'Session expired or already saved.', showAlert: true);
            return;
        }

        $target = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $targetId)
            ->first();

        if ($target === null) {
            $this->telegram->answerCallbackQuery($callbackId, 'Member not found.', showAlert: true);
            return;
        }

        $newRole = $session['role'];
        $overrides = $session['overrides'] ?? [];
        $locationIds = $session['location_ids'] ?? [];

        // Save via authoritative PermissionService
        try {
            $this->permissionService->updatePermissions(
                $target,
                $overrides,
                $locationIds,
                $newRole,
                $user
            );
        } catch (\InvalidArgumentException $e) {
            $this->telegram->answerCallbackQuery($callbackId, $e->getMessage(), showAlert: true);
            return;
        }

        // Clear session
        Cache::forget($cacheKey);

        $this->telegram->answerCallbackQuery(
            $callbackId,
            $locale === 'en' ? 'Permissions saved successfully!' : 'ፈቃዶች በተሳካ ሁኔታ ተቀምጠዋል!'
        );

        // Notify the target member if they have telegram_id
        if (!empty($target->telegram_id)) {
            $targetLocale = $target->language ?? 'am';
            $roleLabel = ucfirst(str_replace('_', ' ', $newRole));
            $notifyMsg = $targetLocale === 'en'
                ? "🔔 <b>Your shop permissions have been updated!</b>\nYour role is now: <b>{$roleLabel}</b>\nTap /start or use the menu to see your updated options."
                : "🔔 <b>የሱቅ ፈቃዶችዎ ተሻሽለዋል!</b>\nየእርስዎ ሚና አሁን: <b>{$roleLabel}</b> ነው\nየተሻሻሉትን ተግባራት ለማየት /start ወይም ሜኑውን ይጠቀሙ።";

            $this->telegram->sendMessage($target->telegram_id, $notifyMsg, [
                'reply_markup' => TelegramUi::mainMenuKeyboard($targetLocale, $target),
            ]);
        }

        ActivityLogger::log(
            (string) $user->shop_id,
            $user->id,
            'team.permissions_updated',
            "Updated permissions for {$target->name} (Role: {$newRole})."
        );

        $this->viewStaffMember($callbackId, $chatId, $messageId, $targetId, $user);
    }

    /**
     * Check whether a feature is allowed given role and overrides in an active session.
     */
    protected function isFeatureAllowedInSession(string $role, string $permKey, array $overrides): bool
    {
        // Deny overrides allow
        if (isset($overrides[$permKey]) && $overrides[$permKey] === 'deny') {
            return false;
        }

        // Allow override
        if (isset($overrides[$permKey]) && $overrides[$permKey] === 'allow') {
            return true;
        }

        // Check role presets
        if ($role === 'owner') {
            return true;
        }

        $presets = PermissionService::ROLE_PRESETS;
        $presetPerms = $presets[$role] ?? [];

        return in_array($permKey, $presetPerms, true);
    }

    /**
     * Toggle staff member active/inactive status.
     */
    public function toggleStaffActive(string $callbackId, int|string $chatId, int $messageId, string $staffUserId, ?User $user): void
    {
        if ($user === null || !$this->permissionService->can($user, 'team.manage')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $staff = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $staffUserId)
            ->first();

        if ($staff !== null) {
            $staff->is_active = !$staff->is_active;
            $staff->save();

            ActivityLogger::log(
                (string) $user->shop_id,
                $user->id,
                'team.status_change',
                "Changed {$staff->name} status to " . ($staff->is_active ? 'active' : 'inactive')
            );
        }

        $this->viewStaffMember($callbackId, $chatId, $messageId, $staffUserId, $user);
    }

    /**
     * Remove staff member from team.
     */
    public function removeStaff(string $callbackId, int|string $chatId, int $messageId, string $staffUserId, ?User $user): void
    {
        if ($user === null || !$this->permissionService->can($user, 'team.manage')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $staff = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $staffUserId)
            ->first();

        if ($staff !== null) {
            if ($staff->role === 'owner') {
                $otherOwners = User::withoutGlobalScope(ShopScope::class)
                    ->where('shop_id', $user->shop_id)
                    ->where('role', 'owner')
                    ->where('is_active', true)
                    ->where('id', '!=', $staff->id)
                    ->count();

                if ($otherOwners === 0) {
                    $this->telegram->answerCallbackQuery($callbackId, 'Cannot remove the last owner.', showAlert: true);
                    return;
                }
            }

            $staffName = $staff->name;
            $staff->delete();

            ActivityLogger::log(
                (string) $user->shop_id,
                $user->id,
                'team.remove',
                "Removed {$staffName} from shop team."
            );

            $this->telegram->answerCallbackQuery($callbackId, "{$staffName} removed from team.");
        }

        $this->handleTeamMenu($chatId, $user);
    }

    /**
     * Toggle staff_can_adjust setting.
     */
    public function toggleStaffAdjust(string $callbackId, int|string $chatId, int $messageId, ?User $user): void
    {
        if ($user === null || $user->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $shop = Shop::find($user->shop_id);
        if ($shop) {
            $shop->staff_can_adjust = !$shop->staff_can_adjust;
            $shop->save();

            ActivityLogger::log(
                (string) $shop->id,
                $user->id,
                'settings.change',
                "Toggled staff_can_adjust to " . ($shop->staff_can_adjust ? 'true' : 'false')
            );
        }

        $this->handleTeamMenu($chatId, $user);
    }

    /**
     * Step 1 of Ownership Transfer: First Warning.
     */
    public function transferInit(string $callbackId, int|string $chatId, int $messageId, string $staffUserId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $staff = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $staffUserId)
            ->first();

        if ($staff === null) {
            $this->telegram->answerCallbackQuery($callbackId, 'Staff not found.');
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $text = $locale === 'en'
            ? "⚠️ <b>Transfer Shop Ownership?</b>\n\nYou are about to transfer full ownership to <b>{$staff->name}</b>.\nYour account will be downgraded to Staff.\n\nDo you want to proceed?"
            : "⚠️ <b>የሱቅ ባለቤትነት ማስተላለፍ ይፈልጋሉ?</b>\n\nሙሉ የሱቅ ባለቤትነትን ለ <b>{$staff->name}</b> ሊያስተላልፉ ነው።\nእርስዎ ወደ ሠራተኛነት ሚና ይቀየራሉ።\n\nይቀጥሉ?";

        $buttons = TelegramUi::inlineKeyboard([
            [
                ['text' => '⚠️ ' . ($locale === 'en' ? 'Yes, Proceed' : 'አዎ ቀጥል'), 'callback_data' => "team:transfer_confirm:{$staff->id}"],
                ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => "team:view:{$staff->id}"],
            ],
        ]);

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => $buttons,
        ]);
    }

    /**
     * Step 2 of Ownership Transfer: Final Double Confirmation.
     */
    public function transferConfirm(string $callbackId, int|string $chatId, int $messageId, string $staffUserId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $staff = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $staffUserId)
            ->first();

        if ($staff === null) {
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $text = $locale === 'en'
            ? "🚨 <b>FINAL CONFIRMATION</b>\n\nThis action CANNOT be reversed. <b>{$staff->name}</b> will become the primary owner with full rights over this shop.\n\nConfirm transfer?"
            : "🚨 <b>የመጨረሻ ማረጋገጫ</b>\n\nይህ ድርጊት አይቀለበስም። <b>{$staff->name}</b> ሙሉ የሱቁ ዋና ባለቤት ይሆናሉ።\n\nማስተላለፉን ያረጋግጣሉ?";

        $buttons = TelegramUi::inlineKeyboard([
            [
                ['text' => '👑 ' . ($locale === 'en' ? 'Confirm Ownership Transfer' : 'አዎ፣ ባለቤትነቱን አስተላልፍ'), 'callback_data' => "team:transfer_execute:{$staff->id}"],
            ],
            [
                ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => "team:view:{$staff->id}"],
            ],
        ]);

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => $buttons,
        ]);
    }

    /**
     * Step 3: Execute Ownership Transfer.
     */
    public function transferExecute(string $callbackId, int|string $chatId, int $messageId, string $staffUserId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $staff = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $staffUserId)
            ->first();

        if ($staff === null) {
            return;
        }

        // Swap roles
        $user->role = 'staff';
        $user->save();

        $staff->role = 'owner';
        $staff->save();

        ActivityLogger::log(
            (string) $user->shop_id,
            $user->id,
            'ownership.transfer',
            "Ownership transferred from {$user->name} to {$staff->name}."
        );

        $this->telegram->answerCallbackQuery($callbackId, 'Ownership transferred successfully!');

        $text = $locale === 'en'
            ? "👑 <b>Ownership Transferred!</b>\n<b>{$staff->name}</b> is now the owner of this shop. Your role has been updated to Staff."
            : "👑 <b>ባለቤትነት ተላልፏል!</b>\n<b>{$staff->name}</b> አሁን የሱቁ ዋና ባለቤት ናቸው። የእርስዎ ሚና ወደ ሠራተኛነት ተቀይሯል።";

        $this->telegram->editMessageText($chatId, $messageId, $text);
    }

    /**
     * View last 30 activity logs for the shop.
     */
    public function viewActivityLogs(string $callbackId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'team.manage')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $logs = ActivityLog::withoutGlobalScope(ShopScope::class)
            ->with('user')
            ->where('shop_id', $user->shop_id)
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get();

        if ($logs->isEmpty()) {
            $text = "📜 " . ($locale === 'en' ? 'No activity logs found.' : 'ምንም የእንቅስቃሴ መዝገብ አልተገኘም።');
        } else {
            $text = "📜 <b>" . ($locale === 'en' ? 'Recent Activity Log (Last 30)' : 'የቅርብ ጊዜ እንቅስቃሴዎች') . "</b>\n\n";
            foreach ($logs as $log) {
                $time = $log->created_at ? $log->created_at->format('M j, H:i') : '';
                $actor = $log->user?->name ?? 'System';
                $text .= "• <code>{$time}</code> [{$actor}]: <b>{$log->action}</b> — {$log->description}\n";
            }
        }

        if (mb_strlen($text) > 4000) {
            $text = mb_substr($text, 0, 3950) . "\n...(truncated)";
        }

        $buttons = TelegramUi::inlineKeyboard([
            [
                ['text' => '◀️ ' . ($locale === 'en' ? 'Back to Team' : 'ወደ ቡድን ተመለስ'), 'callback_data' => 'team:menu:0'],
            ],
        ]);

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => $buttons,
        ]);
    }
}
