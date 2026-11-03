<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\RejectedChange;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\PermissionService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use Illuminate\Support\Str;

class ReviewQueueHandler
{
    public function __construct(
        protected TelegramClient $telegram,
        protected PermissionService $permissionService
    ) {}

    /**
     * Show the owner review queue: pending rejected offline changes.
     */
    public function handleReviewMenu(int|string $chatId, ?User $user, int $page = 1): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || empty($user->shop_id)) {
            $this->telegram->sendMessage($chatId, __('unknown_command'));
            return;
        }

        // Only owners may access review queue
        if ($user->role !== 'owner') {
            $this->telegram->sendMessage($chatId, __('permission_denied'));
            return;
        }

        $shop = Shop::find($user->shop_id);
        if ($shop === null) {
            return;
        }

        $pending = RejectedChange::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->where('status', 'pending')
            ->with(['user', 'device'])
            ->orderByDesc('created_at')
            ->get();

        if ($pending->isEmpty()) {
            $msg = $locale === 'en'
                ? "📥 <b>Owner Review Queue — {$shop->name}</b>\n\n✅ <b>All clear!</b> There are no pending rejected offline changes to review."
                : "📥 <b>የማረጋገጫ ወረፋ — {$shop->name}</b>\n\n✅ <b>ምንም የለም!</b> ማረጋገጫ የሚጠብቅ ውድቅ የተደረገ የኦፍላይን ለውጥ የለም።";

            $this->telegram->sendMessage($chatId, $msg, [
                'reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user),
            ]);
            return;
        }

        $total = $pending->count();
        $perPage = 1; // Show one change in detail per card
        $totalPages = (int) ceil($total / $perPage);
        $page = max(1, min($page, $totalPages));
        $currentIndex = $page - 1;

        /** @var RejectedChange $item */
        $item = $pending->get($currentIndex);

        $submitterName = $item->user?->name ?? 'Unknown Staff';
        $deviceName = $item->device?->name ?? 'Device ' . substr((string) $item->device_id, 0, 8);
        $op = strtoupper($item->op);
        $entity = ucfirst($item->entity);
        $reason = $item->reason;
        $dateFormatted = $item->created_at?->format('M j, Y H:i') ?? '—';

        // Extract human-readable details
        $data = $item->change_data ?? [];
        $summary = '';
        if ($item->entity === 'movement') {
            $mType = $data['type'] ?? 'movement';
            $qty = $data['qty'] ?? '0';
            $summary = "• Type: <b>{$mType}</b>\n• Qty: <b>{$qty}</b>";
        } elseif ($item->entity === 'product') {
            $pData = $data['data'] ?? $data;
            $pName = $pData['name'] ?? 'Product';
            $sellPrice = isset($pData['sell_price_cents']) ? number_format($pData['sell_price_cents'] / 100, 2) . ' ETB' : '—';
            $summary = "• Product: <b>{$pName}</b>\n• Sell Price: <b>{$sellPrice}</b>";
        } elseif ($item->entity === 'location') {
            $lData = $data['data'] ?? $data;
            $lName = $lData['name'] ?? 'Location';
            $summary = "• Location: <b>{$lName}</b>";
        }

        if ($locale === 'en') {
            $text = "📥 <b>Needs Review — Item {$page} of {$total}</b>\n\n"
                . "👤 <b>Submitted by:</b> {$submitterName} ({$deviceName})\n"
                . "📝 <b>Action:</b> {$op} {$entity}\n"
                . "⚠️ <b>Rejection Reason:</b> <code>{$reason}</code>\n"
                . "📅 <b>Date:</b> {$dateFormatted}\n\n"
                . "<b>Payload Details:</b>\n{$summary}\n\n"
                . "<i>Do you want to approve and apply this change, or dismiss it?</i>";
        } else {
            $text = "📥 <b>ማረጋገጫ የሚጠብቅ — {$page} ከ {$total}</b>\n\n"
                . "👤 <b>ያቀረበው:</b> {$submitterName} ({$deviceName})\n"
                . "📝 <b>ተግባር:</b> {$op} {$entity}\n"
                . "⚠️ <b>ውድቅ የሆነበት ምክንያት:</b> <code>{$reason}</code>\n"
                . "📅 <b>ቀን:</b> {$dateFormatted}\n\n"
                . "<b>ዝርዝር መረጃ:</b>\n{$summary}\n\n"
                . "<i>ይህን ለውጥ ማጽደቅ ይፈልጋሉ ወይስ መሰረዝ?</i>";
        }

        $buttons = [];
        $buttons[] = [
            ['text' => '✅ ' . ($locale === 'en' ? 'Approve' : 'አጽድቅ'), 'callback_data' => "rq:app:{$item->id}"],
            ['text' => '❌ ' . ($locale === 'en' ? 'Dismiss' : 'ሰርዝ'), 'callback_data' => "rq:dsm:{$item->id}"],
        ];

        // Pagination row if > 1 items
        if ($totalPages > 1) {
            $navRow = [];
            if ($page > 1) {
                $prev = $page - 1;
                $navRow[] = ['text' => '◀️ ' . ($locale === 'en' ? 'Prev' : 'ወደኋላ'), 'callback_data' => "rq:page:{$prev}"];
            }
            $navRow[] = ['text' => "{$page} / {$totalPages}", 'callback_data' => 'noop:none:0'];
            if ($page < $totalPages) {
                $next = $page + 1;
                $navRow[] = ['text' => ($locale === 'en' ? 'Next' : 'ወደፊት') . ' ▶️', 'callback_data' => "rq:page:{$next}"];
            }
            $buttons[] = $navRow;
        }

        $buttons[] = [
            ['text' => '⚙️ ' . ($locale === 'en' ? 'Settings' : 'ቅንብሮች'), 'callback_data' => 'settings:menu:0'],
        ];

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Handle Approve callback: rq:app:<id>
     */
    public function handleApprove(string $callbackId, int|string $chatId, int $messageId, string $changeId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || $user->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $change = RejectedChange::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $changeId)
            ->first();

        if ($change === null || $change->status !== 'pending') {
            $this->telegram->answerCallbackQuery($callbackId, 'Change already processed or not found.', showAlert: true);
            return;
        }

        $success = $this->permissionService->approveRejectedChange($change, $user);
        if ($success) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                $locale === 'en' ? 'Change approved and applied!' : 'ለውጡ ጸድቋል እና ተግባራዊ ሆኗል!'
            );
        } else {
            $this->telegram->answerCallbackQuery($callbackId, 'Could not approve change.', showAlert: true);
        }

        $this->handleReviewMenu($chatId, $user, 1);
    }

    /**
     * Handle Dismiss callback: rq:dsm:<id>
     */
    public function handleDismiss(string $callbackId, int|string $chatId, int $messageId, string $changeId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || $user->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $change = RejectedChange::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $changeId)
            ->first();

        if ($change === null || $change->status !== 'pending') {
            $this->telegram->answerCallbackQuery($callbackId, 'Change already processed or not found.', showAlert: true);
            return;
        }

        $this->permissionService->dismissRejectedChange($change, $user);
        $this->telegram->answerCallbackQuery(
            $callbackId,
            $locale === 'en' ? 'Change dismissed.' : 'ለውጡ ተሰርዟል።'
        );

        $this->handleReviewMenu($chatId, $user, 1);
    }
}
