<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Context\CurrentShopContext;
use App\Models\BotState;
use App\Models\Location;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\ActivityLogger;
use App\Services\LedgerService;
use App\Services\PermissionService;
use App\Services\Telegram\BotStateService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use Illuminate\Support\Str;

class TransferHandler
{
    public function __construct(
        protected TelegramClient $telegram,
        protected BotStateService $botState,
        protected PermissionService $permissionService,
        protected LedgerService $ledger,
        protected LocationHandler $locationHandler
    ) {}

    /**
     * Start the Transfer flow: From -> To -> Product -> Qty -> Confirm.
     */
    public function start(int|string $chatId, ?string $preselectedProductId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || empty($user->shop_id)) {
            return;
        }

        // Re-check permission
        if (!$this->permissionService->can($user, 'transfer.create')) {
            $this->telegram->sendMessage($chatId, __('permission_denied'));
            return;
        }

        $shop = Shop::find($user->shop_id);
        $activeLocations = Location::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->where('is_active', true)
            ->whereNull('deleted_at_ms')
            ->get();

        if ($activeLocations->count() < 2) {
            $msg = $locale === 'en'
                ? "⚠️ <b>Multiple Locations Required</b>\n\nYou need at least two active locations (e.g. Store + Warehouse) to transfer inventory.\nEnable multiple locations in ⚙️ Settings > Location & Transfer Settings."
                : "⚠️ <b>ሁለትና ከዚያ በላይ ቦታዎች ያስፈልጋሉ</b>\n\nዕቃ ለማስተላለፍ ቢያንስ ሁለት ንቁ ቦታዎች (ለምሳሌ ሱቅ + መጋዘን) ሊኖሩ ይገባል።\nበ ⚙️ ቅንብሮች > የቦታዎች አስተዳደር ውስጥ ያስጀምሩ።";

            $this->telegram->sendMessage($chatId, $msg, [
                'reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user),
            ]);
            return;
        }

        // Step 1: Pick "From" location
        $allowedLocations = $this->locationHandler->getAllowedLocations($user);
        $currentLoc = $this->locationHandler->getCurrentLocation($user);

        $this->botState->set($chatId, 'transfer', 'pick_from', [
            'preselected_product_id' => $preselectedProductId,
        ]);

        $rows = [];
        foreach ($allowedLocations as $loc) {
            $kindIcon = $loc->kind === 'warehouse' ? '🏭' : '🏬';
            $isCur = $currentLoc && $currentLoc->id === $loc->id ? ' (Current)' : '';
            $rows[] = [
                ['text' => "{$kindIcon} {$loc->name}{$isCur}", 'callback_data' => "trans:from:{$loc->id}"],
            ];
        }

        $rows[] = [
            ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'trans:cancel:0'],
        ];

        $text = $locale === 'en'
            ? "🚚 <b>New Inventory Transfer — Step 1/4</b>\n\n📍 <b>Where are you transferring items FROM?</b>\nSelect source location:"
            : "🚚 <b>ዕቃ ማስተላለፍ — ደረጃ 1/4</b>\n\n📍 <b>ዕቃው የሚላከው ከየትኛው ቦታ ነው?</b>\nመነሻ ቦታ ይምረጡ:";

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($rows),
        ]);
    }

    /**
     * Step 2: From location selected -> Pick "To" location.
     */
    public function handleFromLocation(string $cqId, int|string $chatId, int $messageId, string $fromLocId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if (!$this->permissionService->can($user, 'transfer.create', $fromLocId)) {
            $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);

        $fromLoc = Location::withoutGlobalScope(ShopScope::class)->find($fromLocId);
        if ($fromLoc === null) {
            return;
        }

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];
        $data['from_location_id'] = $fromLoc->id;
        $data['from_location_name'] = $fromLoc->name;

        $this->botState->set($chatId, 'transfer', 'pick_to', $data);

        // Destination candidates: all active locations in shop except source location
        $toLocations = Location::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', '!=', $fromLoc->id)
            ->where('is_active', true)
            ->whereNull('deleted_at_ms')
            ->get();

        $rows = [];
        foreach ($toLocations as $loc) {
            $kindIcon = $loc->kind === 'warehouse' ? '🏭' : '🏬';
            $rows[] = [
                ['text' => "{$kindIcon} {$loc->name}", 'callback_data' => "trans:to:{$loc->id}"],
            ];
        }

        $rows[] = [
            ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'trans:cancel:0'],
        ];

        $text = $locale === 'en'
            ? "🚚 <b>New Inventory Transfer — Step 2/4</b>\nFrom: <b>{$fromLoc->name}</b>\n\n📍 <b>Where are you transferring items TO?</b>\nSelect destination location:"
            : "🚚 <b>ዕቃ ማስተላለፍ — ደረጃ 2/4</b>\nመነሻ: <b>{$fromLoc->name}</b>\n\n📍 <b>ዕቃው የሚላከው ወዴት ነው?</b>\nመዳረሻ ቦታ ይምረጡ:";

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($rows),
        ]);
    }

    /**
     * Step 3: Destination selected -> Select Product.
     */
    public function handleToLocation(string $cqId, int|string $chatId, int $messageId, string $toLocId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $toLoc = Location::withoutGlobalScope(ShopScope::class)->find($toLocId);
        if ($toLoc === null) {
            return;
        }

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];
        $data['to_location_id'] = $toLoc->id;
        $data['to_location_name'] = $toLoc->name;

        $preselectedId = $data['preselected_product_id'] ?? null;
        if ($preselectedId) {
            $product = Product::withoutGlobalScope(ShopScope::class)->find($preselectedId);
            if ($product !== null) {
                $this->promptQuantityInput($chatId, $product, $data, $user);
                return;
            }
        }

        $this->botState->set($chatId, 'transfer', 'pick_product', $data);

        // Fetch products with stock > 0 at source location, or list available products
        $products = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('is_active', true)
            ->whereNull('deleted_at_ms')
            ->orderBy('name', 'asc')
            ->limit(10)
            ->get();

        $rows = [];
        foreach ($products as $p) {
            $stock = $this->ledger->stockOf($p, $data['from_location_id']);
            $stockStr = LedgerService::formatQty($stock);
            $rows[] = [
                ['text' => "📦 {$p->name} ({$stockStr} {$p->unit})", 'callback_data' => "trans:prod:{$p->id}"],
            ];
        }

        $rows[] = [
            ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'trans:cancel:0'],
        ];

        $fromName = $data['from_location_name'] ?? 'Source';
        $toName = $toLoc->name;

        $text = $locale === 'en'
            ? "🚚 <b>New Inventory Transfer — Step 3/4</b>\nFrom: <b>{$fromName}</b> → To: <b>{$toName}</b>\n\n📦 <b>Select product to transfer</b> (or type product name):"
            : "🚚 <b>ዕቃ ማስተላለፍ — ደረጃ 3/4</b>\nመነሻ: <b>{$fromName}</b> → መዳረሻ: <b>{$toName}</b>\n\n📦 <b>የሚተላለፈውን ዕቃ ይምረጡ</b> (ወይም ስሙን ጽፈው ይላኩ):";

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($rows),
        ]);
    }

    /**
     * Step 4: Product selected -> Prompt Quantity.
     */
    public function handleProductSelected(string $cqId, int|string $chatId, int $messageId, string $productId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $this->telegram->answerCallbackQuery($cqId);

        $product = Product::withoutGlobalScope(ShopScope::class)->find($productId);
        if ($product === null) {
            return;
        }

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];

        $this->promptQuantityInput($chatId, $product, $data, $user);
    }

    protected function promptQuantityInput(int|string $chatId, Product $product, array $data, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $data['product_id'] = $product->id;
        $data['product_name'] = $product->name;
        $data['unit'] = $product->unit;

        $fromLocId = $data['from_location_id'] ?? null;
        $stock = $this->ledger->stockOf($product, $fromLocId);
        $data['available_stock'] = $stock;

        $this->botState->set($chatId, 'transfer', 'await_qty', $data);

        $stockFormatted = LedgerService::formatQty($stock);
        $fromName = $data['from_location_name'] ?? 'Source';
        $toName = $data['to_location_name'] ?? 'Destination';

        $text = $locale === 'en'
            ? "🚚 <b>New Inventory Transfer — Step 4/4</b>\n"
                . "From: <b>{$fromName}</b> → To: <b>{$toName}</b>\n"
                . "Product: <b>{$product->name}</b>\n"
                . "Available stock at {$fromName}: <b>{$stockFormatted} {$product->unit}</b>\n\n"
                . "⌨️ <b>Enter quantity to transfer</b> (e.g. 5 or 2.5):"
            : "🚚 <b>ዕቃ ማስተላለፍ — ደረጃ 4/4</b>\n"
                . "መነሻ: <b>{$fromName}</b> → መዳረሻ: <b>{$toName}</b>\n"
                . "ዕቃ: <b>{$product->name}</b>\n"
                . "በ{$fromName} ያለው ስቶክ: <b>{$stockFormatted} {$product->unit}</b>\n\n"
                . "⌨️ <b>የሚተላለፈውን ብዛት ይፃፉ</b> (ለምሳሌ 5 ወይም 2.5):";

        $buttons = TelegramUi::inlineKeyboard([
            [
                ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'trans:cancel:0'],
            ],
        ]);

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => $buttons,
        ]);
    }

    /**
     * Quantity entered via text -> Prompt Confirmation.
     */
    public function handleQuantityEntered(int|string $chatId, float $qty, BotState $state, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($qty <= 0) {
            $this->telegram->sendMessage($chatId, __('invalid_number'));
            return;
        }

        $data = $state->data;
        $data['qty'] = $qty;

        $this->botState->set($chatId, 'transfer', 'confirm', $data);

        $fromName = $data['from_location_name'] ?? 'Source';
        $toName = $data['to_location_name'] ?? 'Destination';
        $prodName = $data['product_name'] ?? 'Product';
        $unit = $data['unit'] ?? 'pcs';

        $shop = Shop::find($user->shop_id);
        $modeDesc = $shop->transfer_requires_receipt
            ? ($locale === 'en' ? 'Two-step (Receiver will confirm receipt)' : 'ሁለት-ደረጃ (ተቀባዩ ማረጋገጫ ይሰጣል)')
            : ($locale === 'en' ? 'Instant (Inventory transfers immediately)' : 'ፈጣን (ወዲያውኑ ይፈጸማል)');

        $text = $locale === 'en'
            ? "🚚 <b>Confirm Inventory Transfer</b>\n\n"
                . "• From: <b>{$fromName}</b>\n"
                . "• To: <b>{$toName}</b>\n"
                . "• Product: <b>{$prodName}</b>\n"
                . "• Quantity: <b>{$qty} {$unit}</b>\n"
                . "• Mode: <i>{$modeDesc}</i>\n\n"
                . "Do you want to confirm this transfer?"
            : "🚚 <b>የዕቃ ዝውውር ማረጋገጫ</b>\n\n"
                . "• መነሻ: <b>{$fromName}</b>\n"
                . "• መዳረሻ: <b>{$toName}</b>\n"
                . "• ዕቃ: <b>{$prodName}</b>\n"
                . "• ብዛት: <b>{$qty} {$unit}</b>\n"
                . "• ዓይነት: <i>{$modeDesc}</i>\n\n"
                . "ዝውውሩ እንዲፈጸም ያረጋግጣሉ?";

        $buttons = TelegramUi::inlineKeyboard([
            [
                ['text' => '✅ ' . ($locale === 'en' ? 'Confirm Transfer' : 'አዎ፣ አስተላልፍ'), 'callback_data' => 'trans:confirm:0'],
                ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'trans:cancel:0'],
            ],
        ]);

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => $buttons,
        ]);
    }

    /**
     * Execute confirmed transfer.
     */
    public function executeTransfer(string $cqId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $state = $this->botState->get($chatId);
        if ($state === null || $state->flow !== 'transfer') {
            $this->telegram->answerCallbackQuery($cqId);
            return;
        }

        $data = $state->data;
        $fromLocId = $data['from_location_id'] ?? '';
        $toLocId = $data['to_location_id'] ?? '';
        $productId = $data['product_id'] ?? '';
        $qty = (float) ($data['qty'] ?? 0);

        if (!$this->permissionService->can($user, 'transfer.create', $fromLocId)) {
            $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);

        $product = Product::withoutGlobalScope(ShopScope::class)->find($productId);
        $fromLoc = Location::withoutGlobalScope(ShopScope::class)->find($fromLocId);
        $toLoc = Location::withoutGlobalScope(ShopScope::class)->find($toLocId);

        if ($product === null || $fromLoc === null || $toLoc === null || $qty <= 0) {
            $this->telegram->sendMessage($chatId, 'Transfer data invalid.');
            return;
        }

        try {
            $result = $this->ledger->transfer(
                product: $product,
                fromLocation: $fromLoc,
                toLocation: $toLoc,
                qty: $qty,
                user: $user
            );

            $this->botState->clear($chatId);

            $unit = $product->unit;
            $transferId = $result['transfer_id'];

            if ($result['status'] === 'completed') {
                $successMsg = $locale === 'en'
                    ? "✅ <b>Transfer Completed!</b>\nTransferred <b>{$qty} {$unit}</b> of <b>{$product->name}</b> from <b>{$fromLoc->name}</b> to <b>{$toLoc->name}</b> instantly."
                    : "✅ <b>ዝውውሩ ተጠናቋል!</b>\n<b>{$qty} {$unit}</b> <b>{$product->name}</b> ከ<b>{$fromLoc->name}</b> ወደ <b>{$toLoc->name}</b> ወዲያውኑ ተላልፏል።";

                $this->telegram->editMessageText($chatId, $messageId, $successMsg);
            } else {
                // Two-step receipt mode
                $inTransitMsg = $locale === 'en'
                    ? "🚚 <b>Transfer Dispatched (In Transit)!</b>\nSent <b>{$qty} {$unit}</b> of <b>{$product->name}</b> from <b>{$fromLoc->name}</b> to <b>{$toLoc->name}</b>.\nTransfer ID: <code>{$transferId}</code>\nAwaiting receipt confirmation at {$toLoc->name}."
                    : "🚚 <b>ዕቃው ተልኳል (በጉዞ ላይ)!</b>\n<b>{$qty} {$unit}</b> <b>{$product->name}</b> ከ<b>{$fromLoc->name}</b> ወደ <b>{$toLoc->name}</b> ተልኳል።\nየዝውውር ቁጥር: <code>{$transferId}</code>\nበ{$toLoc->name} እስኪረከቡ ይጠበቃል።";

                $this->telegram->editMessageText($chatId, $messageId, $inTransitMsg);

                // Notify receivers at destination location
                $this->notifyReceivers($user, $toLoc, $fromLoc, $product, $qty, $transferId);
            }
        } catch (\Throwable $e) {
            $this->telegram->sendMessage($chatId, "❌ " . $e->getMessage());
        }
    }

    /**
     * Notify destination users with receipt buttons.
     */
    protected function notifyReceivers(
        User $sender,
        Location $toLoc,
        Location $fromLoc,
        Product $product,
        float $qty,
        string $transferId
    ): void {
        $shopId = (string) $sender->shop_id;
        $users = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('is_active', true)
            ->whereNotNull('telegram_id')
            ->where('id', '!=', $sender->id)
            ->get();

        foreach ($users as $u) {
            if ($this->permissionService->can($u, 'transfer.receive', $toLoc->id)) {
                $locale = $u->language ?? 'am';
                $notice = $locale === 'en'
                    ? "🚚 <b>Inbound Inventory Transfer Incoming!</b>\n"
                        . "• Product: <b>{$product->name}</b>\n"
                        . "• Sent Quantity: <b>{$qty} {$product->unit}</b>\n"
                        . "• From: <b>{$fromLoc->name}</b> → To: <b>{$toLoc->name}</b>\n"
                        . "• Dispatched by: <b>{$sender->name}</b>\n\n"
                        . "Please confirm receipt when the goods arrive:"
                    : "🚚 <b>ዕቃ ወደ እርሶ እየመጣ ነው!</b>\n"
                        . "• ዕቃ: <b>{$product->name}</b>\n"
                        . "• የተላከው ብዛት: <b>{$qty} {$product->unit}</b>\n"
                        . "• መነሻ: <b>{$fromLoc->name}</b> → መዳረሻ: <b>{$toLoc->name}</b>\n"
                        . "• የላከው: <b>{$sender->name}</b>\n\n"
                        . "ዕቃው ሲደርስዎ እባክዎ መቀበልዎን ያረጋግጡ:";

                $buttons = TelegramUi::inlineKeyboard([
                    [
                        ['text' => "✅ " . ($locale === 'en' ? "Receive ({$qty} {$product->unit})" : "ተረከብኩ ({$qty} {$product->unit})"), 'callback_data' => "trans:recv:{$transferId}"],
                        ['text' => "✏️ " . ($locale === 'en' ? 'Different Qty' : 'የተለየ ብዛት'), 'callback_data' => "trans:diff_p:{$transferId}"],
                    ],
                ]);

                $this->telegram->sendMessage($u->telegram_id, $notice, [
                    'reply_markup' => $buttons,
                ]);
            }
        }
    }

    /**
     * Receiver confirms full receipt.
     */
    public function handleReceive(string $cqId, int|string $chatId, int $messageId, string $transferId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        /** @var Movement|null $out */
        $out = Movement::withoutGlobalScope(ShopScope::class)
            ->where('transfer_id', $transferId)
            ->where('type', 'transfer_out')
            ->first();

        if ($out === null) {
            $this->telegram->answerCallbackQuery($cqId, 'Transfer not found.', showAlert: true);
            return;
        }

        $meta = json_decode((string) $out->note, true);
        $toLocId = is_array($meta) ? ($meta['to_location_id'] ?? null) : null;

        if (!$this->permissionService->can($user, 'transfer.receive', $toLocId)) {
            $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);

        $sentQty = abs($out->qty_milli) / 1000;
        $product = Product::withoutGlobalScope(ShopScope::class)->find($out->product_id);

        try {
            $this->ledger->receive(
                transferId: $transferId,
                qtyReceived: $sentQty,
                user: $user
            );

            $msg = $locale === 'en'
                ? "✅ <b>Transfer Received!</b>\nSuccessfully received <b>{$sentQty} {$product?->unit}</b> of <b>{$product?->name}</b> into inventory."
                : "✅ <b>ዕቃው ተረክቧል!</b>\n<b>{$sentQty} {$product?->unit}</b> <b>{$product?->name}</b> በስቶክ ውስጥ ገቢ ሆኗል።";

            $this->telegram->editMessageText($chatId, $messageId, $msg);
        } catch (\Throwable $e) {
            $this->telegram->sendMessage($chatId, "❌ " . $e->getMessage());
        }
    }

    /**
     * Receiver requests to enter a different received quantity.
     */
    public function handleDifferentQtyPrompt(string $cqId, int|string $chatId, int $messageId, string $transferId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        /** @var Movement|null $out */
        $out = Movement::withoutGlobalScope(ShopScope::class)
            ->where('transfer_id', $transferId)
            ->where('type', 'transfer_out')
            ->first();

        if ($out === null) {
            $this->telegram->answerCallbackQuery($cqId, 'Transfer not found.', showAlert: true);
            return;
        }

        $meta = json_decode((string) $out->note, true);
        $toLocId = is_array($meta) ? ($meta['to_location_id'] ?? null) : null;

        if (!$this->permissionService->can($user, 'transfer.receive', $toLocId)) {
            $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);

        $sentQty = abs($out->qty_milli) / 1000;
        $product = Product::withoutGlobalScope(ShopScope::class)->find($out->product_id);

        $this->botState->set($chatId, 'transfer_diff', 'await_diff_qty', [
            'transfer_id' => $transferId,
            'sent_qty' => $sentQty,
            'product_name' => $product?->name,
            'unit' => $product?->unit,
            'from_location_id' => $out->location_id,
            'to_location_id' => $toLocId,
        ]);

        $prompt = $locale === 'en'
            ? "✏️ <b>Enter Actual Quantity Received</b>\n"
                . "Product: <b>{$product?->name}</b>\n"
                . "Sent Quantity: <b>{$sentQty} {$product?->unit}</b>\n\n"
                . "⌨️ Type the quantity you physically received:"
            : "✏️ <b>ትክክለኛውን የተቀበሉትን ብዛት ያስገቡ</b>\n"
                . "ዕቃ: <b>{$product?->name}</b>\n"
                . "የተላከው ብዛት: <b>{$sentQty} {$product?->unit}</b>\n\n"
                . "⌨️ በእጅዎ የደረሰዎትን ትክክለኛ ብዛት ጽፈው ይላኩ:";

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => TelegramUi::cancelKeyboard($locale),
        ]);
    }

    /**
     * Receiver entered different quantity -> record receipt and alert owner if discrepancy.
     */
    public function handleDifferentQtyEntered(int|string $chatId, float $receivedQty, BotState $state, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $data = $state->data;
        $transferId = (string) ($data['transfer_id'] ?? '');
        $sentQty = (float) ($data['sent_qty'] ?? 0);
        $prodName = $data['product_name'] ?? 'Product';
        $unit = $data['unit'] ?? 'pcs';

        if ($receivedQty <= 0) {
            $this->telegram->sendMessage($chatId, __('invalid_number'));
            return;
        }

        try {
            $this->ledger->receive(
                transferId: $transferId,
                qtyReceived: $receivedQty,
                user: $user
            );

            $this->botState->clear($chatId);

            $diff = $receivedQty - $sentQty;
            $hasDiscrepancy = abs($diff) > 0.0001;

            $msg = $locale === 'en'
                ? "✅ <b>Receipt Recorded!</b>\nReceived <b>{$receivedQty} {$unit}</b> of <b>{$prodName}</b>."
                : "✅ <b>ርክክቡ ተመዝግቧል!</b>\n<b>{$receivedQty} {$unit}</b> <b>{$prodName}</b> ገቢ ሆኗል።";

            if ($hasDiscrepancy) {
                $msg .= $locale === 'en'
                    ? "\n⚠️ <b>Discrepancy detected</b> ({$diff} {$unit}). Shop owner has been notified."
                    : "\n⚠️ <b>የብዛት ልዩነት ታይቷል</b> ({$diff} {$unit})። ለሱቁ ባለቤት ማሳወቂያ ተልኳል።";

                // Alert the shop owner(s)
                $this->alertOwnerDiscrepancy($user, $data, $receivedQty, $diff);
            }

            $this->telegram->sendMessage($chatId, $msg, [
                'reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user),
            ]);
        } catch (\Throwable $e) {
            $this->telegram->sendMessage($chatId, "❌ " . $e->getMessage());
        }
    }

    /**
     * Send Discrepancy Alert to shop owner.
     */
    protected function alertOwnerDiscrepancy(User $receiver, array $data, float $receivedQty, float $diff): void
    {
        $shopId = (string) $receiver->shop_id;
        $owners = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('role', 'owner')
            ->where('is_active', true)
            ->whereNotNull('telegram_id')
            ->get();

        $fromLoc = Location::withoutGlobalScope(ShopScope::class)->find($data['from_location_id'] ?? '');
        $toLoc = Location::withoutGlobalScope(ShopScope::class)->find($data['to_location_id'] ?? '');

        $sentMilli = (int) round(((float) ($data['sent_qty'] ?? 0)) * 1000);
        $recvMilli = (int) round($receivedQty * 1000);
        $diffMilli = (int) round($diff * 1000);

        $sentQtyStr = LedgerService::formatQty($sentMilli);
        $recvQtyStr = LedgerService::formatQty($recvMilli);
        $diffQtyStr = LedgerService::formatQty($diffMilli);

        $prodName = $data['product_name'] ?? 'Product';
        $unit = $data['unit'] ?? 'pcs';
        $transferId = $data['transfer_id'] ?? '';

        foreach ($owners as $owner) {
            $locale = $owner->language ?? 'am';
            $alert = $locale === 'en'
                ? "🚨 <b>TRANSFER DISCREPANCY ALERT!</b>\n\n"
                    . "• Transfer ID: <code>{$transferId}</code>\n"
                    . "• Product: <b>{$prodName}</b>\n"
                    . "• From: <b>{$fromLoc?->name}</b> → To: <b>{$toLoc?->name}</b>\n"
                    . "• Sent: <b>{$sentQtyStr} {$unit}</b>\n"
                    . "• Received: <b>{$recvQtyStr} {$unit}</b>\n"
                    . "• Discrepancy: <b>{$diffQtyStr} {$unit}</b>\n"
                    . "• Received by: <b>{$receiver->name}</b>\n\n"
                    . "Review reports > 'Transfer Discrepancies' for full history."
                : "🚨 <b>የዝውውር ብዛት ልዩነት ማስጠንቀቂያ!</b>\n\n"
                    . "• የዝውውር ቁጥር: <code>{$transferId}</code>\n"
                    . "• ዕቃ: <b>{$prodName}</b>\n"
                    . "• መነሻ: <b>{$fromLoc?->name}</b> → መዳረሻ: <b>{$toLoc?->name}</b>\n"
                    . "• የተላከው: <b>{$sentQtyStr} {$unit}</b>\n"
                    . "• የተረከቡት: <b>{$recvQtyStr} {$unit}</b>\n"
                    . "• ልዩነት: <b>{$diffQtyStr} {$unit}</b>\n"
                    . "• የተረከበው: <b>{$receiver->name}</b>\n\n"
                    . "ሙሉ መረጃውን በሪፖርት > 'የዝውውር ልዩነቶች' ውስጥ ይመልከቱ።";

            $this->telegram->sendMessage($owner->telegram_id, $alert);
        }
    }
}
