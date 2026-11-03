<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Context\CurrentShopContext;
use App\Models\ActivityLog;
use App\Models\Shop;
use App\Models\TeamInvite;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\ActivityLogger;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use App\Support\EthiopicCalendar;
use Carbon\Carbon;
use Illuminate\Support\Str;

class TeamHandler
{
    public function __construct(
        protected TelegramClient $telegram
    ) {}

    /**
     * Show team management menu (Owner only).
     */
    public function handleTeamMenu(int|string $chatId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user?->role !== 'owner') {
            $this->telegram->sendMessage($chatId, __('permission_denied'));
            return;
        }

        $shop = Shop::find($user->shop_id);
        $shopId = (string) $user->shop_id;

        $staffMembers = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('role', 'staff')
            ->orderBy('name', 'asc')
            ->get();

        $rows = [];
        foreach ($staffMembers as $s) {
            $statusIcon = $s->is_active ? '🟢' : '⏸';
            $rows[] = [
                ['text' => "{$statusIcon} {$s->name}", 'callback_data' => "team:view:{$s->id}"],
            ];
        }

        $adjustSetting = ($shop?->staff_can_adjust) ? '✅ Enabled' : '❌ Disabled';
        $toggleAdjustLabel = ($shop?->staff_can_adjust)
            ? '🔧 Staff Adjust: [ Allowed ✅ ]'
            : '🔧 Staff Adjust: [ Disallowed ❌ ]';

        $rows[] = [
            ['text' => '➕ ' . ($locale === 'en' ? 'Invite Staff' : 'አዲስ ሠራተኛ ጋብዝ'), 'callback_data' => 'team:invite:create'],
        ];

        $rows[] = [
            ['text' => $toggleAdjustLabel, 'callback_data' => 'team:toggle:adjust'],
        ];

        $rows[] = [
            ['text' => '📜 ' . ($locale === 'en' ? 'Activity Log (Last 30)' : 'የእንቅስቃሴ መዝገብ'), 'callback_data' => 'team:log:view'],
        ];

        $text = $locale === 'en'
            ? "👥 <b>Team Management — {$shop->name}</b>\n• Staff Members: <b>{$staffMembers->count()}</b>\n• Staff May Adjust: <b>{$adjustSetting}</b>\nTap any member below to manage permissions:"
            : "👥 <b>የቡድን አስተዳደር — {$shop->name}</b>\n• የሠራተኞች ብዛት: <b>{$staffMembers->count()}</b>\n• ሠራተኞች ስቶክ ማስተካከል: <b>{$adjustSetting}</b>\nሠራተኛ ለማስተዳደር ከታች ይምረጡ:";

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

        if ($user?->role !== 'owner') {
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
        $invite = TeamInvite::withoutGlobalScope(\App\Scopes\ShopScope::class)
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
     * View a single staff member card with management actions.
     */
    public function viewStaffMember(string $callbackId, int|string $chatId, int $messageId, string $staffUserId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user?->role !== 'owner') {
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

        $text = "👤 <b>Staff: {$staff->name}</b>\n"
            . "• Role: <b>{$staff->role}</b>\n"
            . "• Status: <b>{$statusStr}</b>\n"
            . "• Joined: " . $staff->created_at?->format('M j, Y');

        $buttons = TelegramUi::inlineKeyboard([
            [
                ['text' => $toggleLabel, 'callback_data' => "team:toggle_active:{$staff->id}"],
                ['text' => '🗑 ' . ($locale === 'en' ? 'Remove' : 'አስወግድ'), 'callback_data' => "team:remove:{$staff->id}"],
            ],
            [
                ['text' => '👑 ' . ($locale === 'en' ? 'Transfer Ownership' : 'የባለቤትነት ማስተላለፍ'), 'callback_data' => "team:transfer_init:{$staff->id}"],
            ],
            [
                ['text' => '◀️ ' . ($locale === 'en' ? 'Back' : 'ተመለስ'), 'callback_data' => 'team:menu:0'],
            ],
        ]);

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => $buttons,
        ]);
    }

    /**
     * Toggle staff member active/inactive status.
     */
    public function toggleStaffActive(string $callbackId, int|string $chatId, int $messageId, string $staffUserId, ?User $user): void
    {
        if ($user?->role !== 'owner') {
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
        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $staff = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $staffUserId)
            ->first();

        if ($staff !== null) {
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
        if ($user?->role !== 'owner') {
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

        if ($user?->role !== 'owner') {
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

        // Truncate to safe length under 4000 chars
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
