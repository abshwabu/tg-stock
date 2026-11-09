<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\BotState;
use App\Models\Change;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Location;
use App\Models\Movement;
use App\Models\PairingCode;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\PermissionService;
use App\Services\Telegram\Handlers\AdjustHandler;
use App\Services\Telegram\Handlers\LocationHandler;
use App\Services\Telegram\Handlers\MenuHandler;
use App\Services\Telegram\Handlers\PairingHandler;
use App\Services\Telegram\Handlers\DubeHandler;
use App\Services\Telegram\Handlers\ProductHandler;
use App\Services\Telegram\Handlers\ReportHandler;
use App\Services\Telegram\Handlers\ReviewQueueHandler;
use App\Services\Telegram\Handlers\SaleHandler;
use App\Services\Telegram\Handlers\SettingsHandler;
use App\Services\Telegram\Handlers\StartHandler;
use App\Services\Telegram\Handlers\StockInHandler;
use App\Services\Telegram\Handlers\TeamHandler;
use App\Services\Telegram\Handlers\TransferHandler;
use App\Services\Telegram\Handlers\UndoHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use App\Support\ImmediateTransaction;

class TelegramRouter
{
    public function __construct(
        protected TelegramClient $telegram,
        protected BotStateService $botState,
        protected StartHandler $startHandler,
        protected PairingHandler $pairingHandler,
        protected SettingsHandler $settingsHandler,
        protected MenuHandler $menuHandler,
        protected ProductHandler $productHandler,
        protected StockInHandler $stockInHandler,
        protected SaleHandler $saleHandler,
        protected AdjustHandler $adjustHandler,
        protected UndoHandler $undoHandler,
        protected ReportHandler $reportHandler,
        protected TeamHandler $teamHandler,
        protected LocationHandler $locationHandler,
        protected TransferHandler $transferHandler,
        protected ReviewQueueHandler $reviewQueueHandler,
        protected DubeHandler $dubeHandler,
        protected PermissionService $permissionService
    ) {}

    /**
     * Route and process an incoming Telegram update.
     */
    public function dispatch(array $update): void
    {
        // 1. Handle callback_query (inline buttons)
        if (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);
            return;
        }

        // 2. Handle message
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
            return;
        }

        Log::info('Telegram update received without message or callback_query', [
            'update_id' => $update['update_id'] ?? null,
        ]);
    }

    /**
     * Handle incoming callback query.
     */
    protected function handleCallbackQuery(array $cq): void
    {
        $cqId = (string) ($cq['id'] ?? '');
        $from = $cq['from'] ?? [];
        $chatId = (int) ($cq['message']['chat']['id'] ?? $from['id'] ?? 0);
        $messageId = (int) ($cq['message']['message_id'] ?? 0);
        $data = (string) ($cq['data'] ?? '');

        if (empty($chatId)) {
            $this->telegram->answerCallbackQuery($cqId);
            return;
        }

        $user = $this->resolveUser($chatId);
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        // Check rate limit per-user (30 actions / minute)
        if ($this->isRateLimited($chatId)) {
            $this->telegram->answerCallbackQuery($cqId, __('rate_limited'), showAlert: true);
            return;
        }

        // Callback data format: module:action:id (<64 bytes)
        $parts = explode(':', $data);
        $module = $parts[0] ?? '';
        $action = $parts[1] ?? '';
        $id = $parts[2] ?? '';

        // 0.5 Main Menu Navigation Callbacks
        if ($module === 'deleteaccount') {
            $this->handleDeleteAccountCallback($cqId, $chatId, $action, $user);
            return;
        }
        if ($module === 'menu') {
            $this->handleMenuCallback($cqId, $chatId, $messageId, $action, $id, $user);
            return;
        }

        // 1. Pairing
        if ($module === 'pair') {
            $this->pairingHandler->handleCallback($cqId, $chatId, $messageId, $action, $id, $from, $user);
            return;
        }

        // 1.5 Locations
        if ($module === 'loc') {
            $this->handleLocationCallback($cqId, $chatId, $messageId, $action, $id, $parts, $user);
            return;
        }

        // 1.6 Transfers
        if ($module === 'trans') {
            $this->handleTransferCallback($cqId, $chatId, $messageId, $action, $id, $parts, $user);
            return;
        }

        // 1.7 Owner Review Queue
        if ($module === 'rq') {
            $this->handleReviewQueueCallback($cqId, $chatId, $messageId, $action, $id, $parts, $user);
            return;
        }

        // 1.8 Devices Management
        if ($module === 'devices') {
            $this->handleDevicesCallback($cqId, $chatId, $messageId, $action, $id, $user);
            return;
        }

        // 2. Language & Settings
        if ($module === 'lang') {
            $this->settingsHandler->handleLanguageCallback($cqId, $chatId, $messageId, $action, $id, $user);
            return;
        }

        if ($module === 'settings') {
            if ($action === 'pay') {
                $subAction = $id;
                if ($subAction === 'menu') {
                    $this->settingsHandler->handlePaymentSettingsMenu($cqId, $chatId, $messageId, $user);
                } elseif ($subAction === 'tog_m') {
                    $method = $parts[3] ?? '';
                    $this->settingsHandler->handleTogglePaymentMethod($cqId, $chatId, $messageId, $method, $user);
                } elseif ($subAction === 'tog_ref') {
                    $this->settingsHandler->handleToggleRequireReference($cqId, $chatId, $messageId, $user);
                } elseif ($subAction === 'd_days') {
                    $days = (int) ($parts[3] ?? 14);
                    $this->settingsHandler->handleSetDefaultDubeDays($cqId, $chatId, $messageId, $days, $user);
                } elseif ($subAction === 'favs') {
                    $kind = $parts[3] ?? 'bank';
                    $page = (int) ($parts[4] ?? 1);
                    $this->settingsHandler->handleFavoriteProvidersList($cqId, $chatId, $messageId, $kind, $page, $user);
                } elseif ($subAction === 'tog_fav') {
                    $kind = $parts[3] ?? 'bank';
                    $provKey = $parts[4] ?? '';
                    $page = (int) ($parts[5] ?? 1);
                    $this->settingsHandler->handleToggleProviderFavorite($cqId, $chatId, $messageId, $kind, $provKey, $page, $user);
                }
                return;
            }

            if ($action === 'toggle' && $id === 'neg_stock') {
                $this->settingsHandler->handleToggleNegativeStock($cqId, $chatId, $messageId, $user);
            } elseif ($action === 'toggle' && $id === 'calendar') {
                $this->settingsHandler->handleToggleCalendar($cqId, $chatId, $messageId, $user);
            } else {
                $this->telegram->answerCallbackQuery($cqId);
            }
            return;
        }

        // 3. Reports
        if ($module === 'rep') {
            $this->handleReportCallback($cqId, $chatId, $messageId, $action, $id, $parts, $user);
            return;
        }

        // 4. Team & Staff Management
        if ($module === 'team') {
            $this->handleTeamCallback($cqId, $chatId, $messageId, $action, $id, $parts, $user);
            return;
        }

        // 3. Products
        if ($module === 'prod') {
            $this->handleProductCallback($cqId, $chatId, $messageId, $action, $id, $parts, $user);
            return;
        }

        // 4. Quick Add
        if ($module === 'qadd') {
            if ($action === 'create') {
                $this->productHandler->executeQuickAdd($cqId, $chatId, $messageId, $user);
            } elseif ($action === 'search') {
                $this->telegram->answerCallbackQuery($cqId);
                $state = $this->botState->get($chatId);
                $query = $state?->data['name'] ?? '';
                $this->botState->clear($chatId);
                if (!empty($query)) {
                    $this->productHandler->handleSearch($chatId, $query, $user);
                }
            }
            return;
        }

        // 5. Stock In
        if ($module === 'stockin') {
            $this->handleStockInCallback($cqId, $chatId, $messageId, $action, $id, $user);
            return;
        }

        // 6. Sale
        if ($module === 'sale') {
            $this->handleSaleCallback($cqId, $chatId, $messageId, $action, $id, $parts, $user);
            return;
        }

        // 7. Adjust
        if ($module === 'adj') {
            $this->handleAdjustCallback($cqId, $chatId, $messageId, $action, $id, $user);
            return;
        }

        // 8. Undo
        if ($module === 'undo') {
            $this->undoHandler->handleUndo($chatId, $user, $cqId);
            return;
        }

        // 9. Dube
        if ($module === 'dube') {
            $this->handleDubeCallback($cqId, $chatId, $messageId, $action, $id, $parts, $user);
            return;
        }

        // Default: acknowledge callback query
        $this->telegram->answerCallbackQuery($cqId);
    }

    /**
     * Dispatch main menu inline button callbacks.
     */
    protected function handleMenuCallback(string $cqId, int $chatId, int $messageId, string $action, string $id, ?User $user): void
    {
        $this->telegram->answerCallbackQuery($cqId);

        match ($action) {
            'products' => $this->productHandler->handleList($chatId, $user, 1),
            'stockin' => $this->stockInHandler->start($chatId, null, $user),
            'sale' => $this->saleHandler->start($chatId, null, $user),
            'dube' => $this->dubeHandler->start($chatId, $user),
            'reports' => $this->reportHandler->handleMenu($chatId, $user),
            'settings' => $this->settingsHandler->handleSettings($chatId, $user),
            'team' => $this->teamHandler->handleTeamMenu($chatId, $user),
            'transfer' => $this->transferHandler->start($chatId, null, $user),
            'switch_loc' => $this->locationHandler->handleSwitchMenu($chatId, $user),
            'review' => $this->reviewQueueHandler->handleReviewMenu($chatId, $user),
            'pair' => $this->promptPairingCode($chatId, $user),
            'devices' => $this->handleDevicesList($chatId, $user),
            default => null,
        };
    }

    /**
     * Prompt user to enter 6-digit code shown on phone screen.
     */
    public function promptPairingCode(int|string $chatId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        $this->botState->start((int) $chatId, 'pairing', 'await_code', ['language' => $locale]);
        $this->telegram->sendMessage(
            $chatId,
            $locale === 'en'
                ? "📱 <b>Link Mobile App</b>\n\nPlease enter the <b>6-digit code</b> shown on your phone's screen (e.g. 104442):"
                : "📱 <b>ስልክ ማገናኛ</b>\n\nእባክዎ በስልክዎ ላይ የሚታየውን ባለ <b>6-አሃዝ ኮድ</b> ያስገቡ (ለምሳሌ 104442):",
            ['reply_markup' => TelegramUi::cancelKeyboard($locale)]
        );
    }

    /**
     * List all linked devices for the shop with revoke action buttons.
     */
    public function handleDevicesList(int|string $chatId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        $shopId = (string) $user?->shop_id;

        $devices = \App\Models\Device::withoutGlobalScope(\App\Scopes\ShopScope::class)
            ->where('shop_id', $shopId)
            ->whereNull('revoked_at')
            ->orderBy('created_at', 'asc')
            ->get();

        $rows = [];
        foreach ($devices as $d) {
            $rows[] = [
                ['text' => "📱 {$d->name}", 'callback_data' => 'devices:none:0'],
                ['text' => '🗑️ ' . ($locale === 'en' ? 'Revoke' : 'ሰርዝ'), 'callback_data' => "devices:revoke:{$d->id}"],
            ];
        }

        $rows[] = [
            ['text' => $locale === 'en' ? '➕ Pair New Phone' : '➕ አዲስ ስልክ አገናኝ', 'callback_data' => 'menu:pair:0'],
        ];

        $title = $locale === 'en'
            ? "📱 <b>Linked Devices ({$devices->count()} active)</b>\n\nManage phones connected to your shop:"
            : "📱 <b>የተገናኙ ስልኮች ({$devices->count()} ንቁ)</b>\n\nከሱቅዎ ጋር የተገናኙ ስልኮች ዝርዝር:";

        $this->telegram->sendMessage($chatId, $title, [
            'reply_markup' => TelegramUi::inlineKeyboard($rows),
        ]);
    }

    /**
     * Handle device management callbacks.
     */
    protected function handleDevicesCallback(string $cqId, int $chatId, int $messageId, string $action, string $id, ?User $user): void
    {
        $locale = $user?->language ?? 'am';

        if ($action === 'list') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->handleDevicesList($chatId, $user);
            return;
        }

        if ($action === 'revoke') {
            $shopId = (string) $user?->shop_id;
            $device = \App\Models\Device::withoutGlobalScope(\App\Scopes\ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('id', $id)
                ->first();

            if ($device) {
                $device->revoked_at = now();
                $device->save();
                $this->telegram->answerCallbackQuery($cqId, $locale === 'en' ? "Device {$device->name} revoked." : "ስልክ {$device->name} ተሰርዟል።");
                $this->handleDevicesList($chatId, $user);
                return;
            }
        }

        $this->telegram->answerCallbackQuery($cqId);
    }

    /**
     * Dispatch product callbacks.
     */
    protected function handleProductCallback(string $cqId, int $chatId, int $messageId, string $action, string $id, array $parts, ?User $user): void
    {
        if ($action === 'page') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->productHandler->handleList($chatId, $user, (int) $id);
            return;
        }

        if ($action === 'view') {
            $this->productHandler->handleView($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'add') {
            $this->telegram->answerCallbackQuery($cqId);
            if ($id === 'start') {
                $this->productHandler->startAddProduct($chatId, $user);
            } elseif ($id === 'skip_opening') {
                $this->botState->next($chatId, 'unit', ['opening_stock_milli' => 0]);
                $this->productHandler->promptUnitSelection($chatId, $user?->language ?? 'am');
            } elseif ($id === 'unit') {
                $unit = $parts[3] ?? 'pcs';
                $this->botState->next($chatId, 'cost_price', ['unit' => $unit]);
                $skipBtn = TelegramUi::inlineKeyboard([
                    [['text' => __('btn_skip'), 'callback_data' => 'prod:add:skip_cost']],
                ]);
                $this->telegram->sendMessage($chatId, __('add_product_cost_price'), [
                    'reply_markup' => $skipBtn,
                ]);
            } elseif ($id === 'skip_cost') {
                $state = $this->botState->get($chatId);
                if ($state !== null) {
                    $this->productHandler->finalizeProductCreation($chatId, 0, $state->data, $user);
                }
            }
            return;
        }

        if ($action === 'price') {
            $this->productHandler->startEditPrice($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'del') {
            $this->productHandler->handleDelete($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'confirm_del') {
            $this->productHandler->confirmDelete($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);
    }

    /**
     * Dispatch stock in callbacks.
     */
    protected function handleStockInCallback(string $cqId, int $chatId, int $messageId, string $action, string $id, ?User $user): void
    {
        if ($action === 'start') {
            $this->telegram->answerCallbackQuery($cqId);
            $prodId = $id !== 'none' ? $id : null;
            $this->stockInHandler->start($chatId, $prodId, $user);
            return;
        }

        if ($action === 'qty') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->stockInHandler->handleQty($chatId, (float) $id, $user);
            return;
        }

        if ($action === 'skip_cost') {
            $this->telegram->answerCallbackQuery($cqId);
            $state = $this->botState->get($chatId);
            if ($state !== null) {
                $this->stockInHandler->finalize($chatId, null, $state->data, $user);
            }
            return;
        }

        if ($action === 'cancel') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->botState->clear($chatId);
            $this->telegram->sendMessage($chatId, __('action_cancelled'), [
                'reply_markup' => TelegramUi::mainMenuKeyboard($user?->language ?? 'am'),
            ]);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);
    }

    /**
     * Dispatch sale callbacks.
     */
    protected function handleSaleCallback(string $cqId, int $chatId, int $messageId, string $action, string $id, array $parts, ?User $user): void
    {
        if ($action === 'start') {
            $this->telegram->answerCallbackQuery($cqId);
            $prodId = $id !== 'none' ? $id : null;
            $this->saleHandler->start($chatId, $prodId, $user);
            return;
        }

        if ($action === 'pick') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->saleHandler->promptQuantity($chatId, $id, $user);
            return;
        }

        if ($action === 'qty') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->saleHandler->handleQuantitySelected($chatId, (float) $id, $user);
            return;
        }

        if ($action === 'finish') {
            $this->saleHandler->finishSale($cqId, $chatId, $messageId, $user);
            return;
        }

        if ($action === 'cancel') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->botState->clear($chatId);
            $this->telegram->sendMessage($chatId, __('action_cancelled'), [
                'reply_markup' => TelegramUi::mainMenuKeyboard($user?->language ?? 'am', $user),
            ]);
            return;
        }

        if ($action === 'cart_back') {
            $this->telegram->answerCallbackQuery($cqId);
            $state = $this->botState->get($chatId);
            $this->saleHandler->showCartSummary($chatId, $state?->data['cart'] ?? [], $user?->language ?? 'am', $user);
            return;
        }

        if ($action === 'pay_step') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->saleHandler->showPaymentStep($chatId, $messageId, $user);
            return;
        }

        if ($action === 'pay_m') {
            if ($id === 'cash') {
                $this->saleHandler->handleCashPaymentSelected($cqId, $chatId, $messageId, $user);
                return;
            }
            if ($id === 'bank') {
                $this->saleHandler->showProviderList($cqId, $chatId, $messageId, 'bank', 1, $user);
                return;
            }
            if ($id === 'wallet') {
                $this->saleHandler->showProviderList($cqId, $chatId, $messageId, 'wallet', 1, $user);
                return;
            }
            if ($id === 'dube') {
                if (!$this->permissionService->can($user, 'sale.dube')) {
                    $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
                    return;
                }
                $this->saleHandler->startDubeStep($cqId, $chatId, $messageId, $user);
                return;
            }
        }

        if ($action === 'prov_pick') {
            $kind = $id;
            $provKey = $parts[3] ?? '';
            $this->saleHandler->handleProviderPicked($cqId, $chatId, $messageId, $kind, $provKey, $user);
            return;
        }

        if ($action === 'prov_page') {
            $kind = $id;
            $page = (int) ($parts[3] ?? 1);
            $this->saleHandler->showProviderList($cqId, $chatId, $messageId, $kind, $page, $user);
            return;
        }

        if ($action === 'prov_other') {
            $this->saleHandler->handleOtherProviderSelected($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'skip_ref') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->saleHandler->handleReferenceCompleted($chatId, null, $user, $messageId);
            return;
        }

        if ($action === 'dube_cust') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->saleHandler->handleDubeCustomerPicked($chatId, $id, $user, $messageId);
            return;
        }

        if ($action === 'dube_new') {
            $this->saleHandler->startDubeAddCustomer($cqId, $chatId, $user);
            return;
        }

        if ($action === 'dube_search') {
            $this->saleHandler->startDubeCustomerSearch($cqId, $chatId, $user);
            return;
        }

        if ($action === 'dube_pnow') {
            if ($id === 'custom') {
                $this->saleHandler->promptCustomPaidNowAmount($cqId, $chatId, $messageId, $user);
            } else {
                $this->telegram->answerCallbackQuery($cqId);
                $this->saleHandler->handleDubePaidNowAmount($chatId, (int) $id, $user, $messageId);
            }
            return;
        }

        if ($action === 'dube_umeth') {
            $this->saleHandler->handleDubeUpfrontMethodPicked($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'dube_due') {
            if ($id === 'custom') {
                $this->saleHandler->promptCustomDubeDueDate($cqId, $chatId, $messageId, $user);
            } else {
                $this->saleHandler->handleDubeDueDateDays($cqId, $chatId, $messageId, (int) $id, $user);
            }
            return;
        }

        if ($action === 'dube_due_pick') {
            $this->saleHandler->handleDubeDueDatePicked($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'dube_confirm') {
            $this->saleHandler->handleDubeConfirm($cqId, $chatId, $messageId, $user);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);
    }

    /**
     * Dispatch Dube callbacks.
     */
    protected function handleDubeCallback(string $cqId, int $chatId, int $messageId, string $action, string $id, array $parts, ?User $user): void
    {
        // Re-check permissions on all dube actions
        if (!$this->permissionService->can($user, 'dube.view')) {
            $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
            return;
        }

        if ($action === 'page') {
            $this->telegram->answerCallbackQuery($cqId);
            $page = is_numeric($id) ? (int) $id : 1;
            $this->dubeHandler->start($chatId, $user, $page);
            return;
        }

        if ($action === 'cust') {
            $this->dubeHandler->showCustomerCard($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'remind') {
            $this->dubeHandler->sendReminderText($cqId, $chatId, $id, $user);
            return;
        }

        if ($action === 'pay_start') {
            if (!$this->permissionService->can($user, 'payment.collect')) {
                $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
                return;
            }
            $this->dubeHandler->startRepayment($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'pay_amt') {
            if (!$this->permissionService->can($user, 'payment.collect')) {
                $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
                return;
            }
            $customerId = $id;
            $type = $parts[3] ?? 'full';
            if ($type === 'custom') {
                $this->dubeHandler->promptCustomRepaymentAmount($cqId, $chatId, $messageId, $customerId, $user);
            } else {
                $this->telegram->answerCallbackQuery($cqId);
                $customer = \App\Models\Customer::withoutGlobalScope(\App\Scopes\ShopScope::class)->find($customerId);
                $balanceService = app(\App\Services\BalanceService::class);
                $credit = $customer ? $balanceService->customerCredit($customer) : ['total_debt_cents' => 0];
                $this->dubeHandler->promptRepaymentMethod($chatId, $customerId, $credit['total_debt_cents'], $user, $messageId);
            }
            return;
        }

        if ($action === 'pay_m') {
            if (!$this->permissionService->can($user, 'payment.collect')) {
                $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
                return;
            }
            if (isset($parts[4])) {
                // Legacy format: dube:pay_m:customerId:method:amountCents
                $customerId = $id;
                $method = $parts[3] ?? 'cash';
                $amountCents = (int) ($parts[4] ?? 0);
            } else {
                // Short format (<64 bytes): dube:pay_m:method
                $state = $this->botState->get($chatId);
                $customerId = (string) ($state?->data['customer_id'] ?? '');
                $amountCents = (int) ($state?->data['amount_cents'] ?? 0);
                $method = $id;
            }

            if (empty($customerId) || $amountCents <= 0) {
                $this->telegram->answerCallbackQuery($cqId, __('session_expired') ?? 'Session expired', showAlert: true);
                return;
            }

            if ($method === 'cash') {
                $this->telegram->answerCallbackQuery($cqId);
                $this->botState->clear($chatId);
                $this->dubeHandler->executeRepayment($chatId, $customerId, $amountCents, 'cash', null, null, $user, $messageId);
            } else {
                $this->dubeHandler->promptRepaymentProvider($cqId, $chatId, $messageId, $customerId, $method, $amountCents, 1, $user);
            }
            return;
        }

        if ($action === 'rpay_prov') {
            if (!$this->permissionService->can($user, 'payment.collect')) {
                $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
                return;
            }
            if (isset($parts[5])) {
                // Legacy format: dube:rpay_prov:customerId:kind:amountCents:providerKey
                $customerId = $id;
                $kind = $parts[3] ?? 'bank';
                $amountCents = (int) ($parts[4] ?? 0);
                $providerKey = $parts[5] ?? '';
            } else {
                // Short format (<64 bytes): dube:rpay_prov:providerKey
                $state = $this->botState->get($chatId);
                $customerId = (string) ($state?->data['customer_id'] ?? '');
                $kind = (string) ($state?->data['kind'] ?? 'bank');
                $amountCents = (int) ($state?->data['amount_cents'] ?? 0);
                $providerKey = $id;
            }

            if (empty($customerId) || $amountCents <= 0 || empty($providerKey)) {
                $this->telegram->answerCallbackQuery($cqId, __('session_expired') ?? 'Session expired', showAlert: true);
                return;
            }

            $this->dubeHandler->handleRepaymentProviderPicked($cqId, $chatId, $messageId, $customerId, $kind, $amountCents, $providerKey, $user);
            return;
        }

        if ($action === 'rpay_page') {
            if (isset($parts[5])) {
                // Legacy format: dube:rpay_page:customerId:kind:amountCents:page
                $customerId = $id;
                $kind = $parts[3] ?? 'bank';
                $amountCents = (int) ($parts[4] ?? 0);
                $page = (int) ($parts[5] ?? 1);
            } else {
                // Short format (<64 bytes): dube:rpay_page:page
                $state = $this->botState->get($chatId);
                $customerId = (string) ($state?->data['customer_id'] ?? '');
                $kind = (string) ($state?->data['kind'] ?? 'bank');
                $amountCents = (int) ($state?->data['amount_cents'] ?? 0);
                $page = (int) ($id ?? 1);
            }

            if (empty($customerId) || $amountCents <= 0) {
                $this->telegram->answerCallbackQuery($cqId, __('session_expired') ?? 'Session expired', showAlert: true);
                return;
            }

            $this->dubeHandler->promptRepaymentProvider($cqId, $chatId, $messageId, $customerId, $kind, $amountCents, $page, $user);
            return;
        }

        if ($action === 'rpay_skip_ref') {
            if (!$this->permissionService->can($user, 'payment.collect')) {
                $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
                return;
            }
            $this->telegram->answerCallbackQuery($cqId);

            if (isset($parts[5])) {
                // Legacy format: dube:rpay_skip_ref:customerId:kind:amountCents:providerKey
                $customerId = $id;
                $kind = $parts[3] ?? 'bank';
                $amountCents = (int) ($parts[4] ?? 0);
                $providerKey = $parts[5] ?? '';
            } else {
                // Short format (<64 bytes): dube:rpay_skip_ref
                $state = $this->botState->get($chatId);
                $customerId = (string) ($state?->data['customer_id'] ?? '');
                $kind = (string) ($state?->data['kind'] ?? 'bank');
                $amountCents = (int) ($state?->data['amount_cents'] ?? 0);
                $providerKey = (string) ($state?->data['provider_key'] ?? '');
            }

            if (empty($customerId) || $amountCents <= 0 || empty($providerKey)) {
                $this->telegram->answerCallbackQuery($cqId, __('session_expired') ?? 'Session expired', showAlert: true);
                return;
            }

            $this->botState->clear($chatId);
            $this->dubeHandler->executeRepayment($chatId, $customerId, $amountCents, $kind, $providerKey, null, $user, $messageId);
            return;
        }

        if ($action === 'ext_menu') {
            if (!$this->permissionService->can($user, 'sale.dube')) {
                $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
                return;
            }
            $this->dubeHandler->showExtendSaleList($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'ext_opt') {
            if (!$this->permissionService->can($user, 'sale.dube')) {
                $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
                return;
            }
            $saleId = $id;
            $customerId = $parts[3] ?? '';
            if (empty($customerId)) {
                $sale = \App\Models\Sale::withoutGlobalScope(\App\Scopes\ShopScope::class)->find($saleId);
                $customerId = (string) ($sale?->customer_id ?? '');
            }
            $this->dubeHandler->showExtendOptions($cqId, $chatId, $messageId, $saleId, $customerId, $user);
            return;
        }

        if ($action === 'ext_do') {
            if (!$this->permissionService->can($user, 'sale.dube')) {
                $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
                return;
            }
            $saleId = $id;
            $days = (int) ($parts[3] ?? 14);
            $customerId = $parts[4] ?? '';
            if (empty($customerId)) {
                $sale = \App\Models\Sale::withoutGlobalScope(\App\Scopes\ShopScope::class)->find($saleId);
                $customerId = (string) ($sale?->customer_id ?? '');
            }
            $shop = \App\Models\Shop::find($user?->shop_id);
            $newDueDate = \Carbon\Carbon::now($shop?->timezone ?: 'Africa/Addis_Ababa')->addDays($days)->toDateString();
            $this->dubeHandler->applyExtendDueDate($cqId, $chatId, $messageId, $saleId, $newDueDate, $customerId, $user);
            return;
        }

        if ($action === 'ext_custom') {
            if (!$this->permissionService->can($user, 'sale.dube')) {
                $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
                return;
            }
            $saleId = $id;
            $customerId = $parts[3] ?? '';
            if (empty($customerId)) {
                $sale = \App\Models\Sale::withoutGlobalScope(\App\Scopes\ShopScope::class)->find($saleId);
                $customerId = (string) ($sale?->customer_id ?? '');
            }
            $this->dubeHandler->promptCustomDueDate($cqId, $chatId, $messageId, $saleId, $customerId, $user);
            return;
        }

        if ($action === 'edit') {
            if (!$this->permissionService->can($user, 'customers.manage')) {
                $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
                return;
            }
            $this->dubeHandler->startEditCustomer($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'add_cust') {
            if (!$this->permissionService->can($user, 'customers.manage')) {
                $this->telegram->answerCallbackQuery($cqId, __('permission_denied'), showAlert: true);
                return;
            }
            $this->dubeHandler->startAddCustomer($cqId, $chatId, $user);
            return;
        }

        if ($action === 'cust_skip_phone') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->dubeHandler->handleCustomerPhoneEntered($chatId, null, $user);
            return;
        }

        if ($action === 'search') {
            $this->dubeHandler->startSearchCustomer($cqId, $chatId, $user);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);
    }

    /**
     * Dispatch adjust callbacks.
     */
    protected function handleAdjustCallback(string $cqId, int $chatId, int $messageId, string $action, string $id, ?User $user): void
    {
        if ($action === 'start') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->adjustHandler->start($chatId, $id, $user);
            return;
        }

        if ($action === 'reason') {
            $this->adjustHandler->finalize($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'cancel') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->botState->clear($chatId);
            $this->telegram->sendMessage($chatId, __('action_cancelled'), [
                'reply_markup' => TelegramUi::mainMenuKeyboard($user?->language ?? 'am'),
            ]);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);
    }

    /**
     * Dispatch location callbacks.
     */
    protected function handleLocationCallback(string $cqId, int $chatId, int $messageId, string $action, string $id, array $parts, ?User $user): void
    {
        if ($action === 'switch' && $id === 'menu') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->locationHandler->handleSwitchMenu($chatId, $user);
            return;
        }

        if ($action === 'set' || ($action === 'switch' && $id === 'pick')) {
            $locId = $action === 'set' ? $id : ($parts[3] ?? '');
            $this->locationHandler->handleSelectLocation($cqId, $chatId, $messageId, $locId, $user);
            return;
        }

        if ($action === 'settings') {
            $this->locationHandler->handleSettingsLocations($cqId, $chatId, $messageId, $user);
            return;
        }

        if ($action === 'view') {
            $this->locationHandler->handleViewLocation($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'wiz' && $id === 'start') {
            $this->locationHandler->handleEnableWarehouseWizard($cqId, $chatId, $messageId, $user);
            return;
        }

        if ($action === 'wiz' && $id === 'stock') {
            $targetLocId = $parts[3] ?? '';
            $this->locationHandler->handleAssignExistingStock($cqId, $chatId, $messageId, $targetLocId, $user);
            return;
        }

        if ($action === 'toggle' && $id === 'receipt') {
            $this->locationHandler->handleToggleReceipt($cqId, $chatId, $messageId, $user);
            return;
        }

        if ($action === 'archive') {
            $this->locationHandler->handleArchiveLocation($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'rename' && $id === 'start') {
            $locId = $parts[3] ?? '';
            $this->locationHandler->startRenameLocation($cqId, $chatId, $messageId, $locId, $user);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);
    }

    /**
     * Dispatch transfer callbacks.
     */
    protected function handleTransferCallback(string $cqId, int $chatId, int $messageId, string $action, string $id, array $parts, ?User $user): void
    {
        if ($action === 'from') {
            $this->transferHandler->handleFromLocation($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'to') {
            $this->transferHandler->handleToLocation($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'prod') {
            $this->transferHandler->handleProductSelected($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'confirm') {
            $this->transferHandler->executeTransfer($cqId, $chatId, $messageId, $user);
            return;
        }

        if ($action === 'cancel') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->botState->clear($chatId);
            $this->telegram->sendMessage($chatId, __('action_cancelled'), [
                'reply_markup' => TelegramUi::mainMenuKeyboard($user?->language ?? 'am', $user),
            ]);
            return;
        }

        if ($action === 'recv') {
            $this->transferHandler->handleReceive($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'diff_p') {
            $this->transferHandler->handleDifferentQtyPrompt($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);
    }

    /**
     * Dispatch review queue callbacks.
     */
    protected function handleReviewQueueCallback(string $cqId, int $chatId, int $messageId, string $action, string $id, array $parts, ?User $user): void
    {
        if ($action === 'menu' || $action === 'page') {
            $this->telegram->answerCallbackQuery($cqId);
            $page = is_numeric($id) ? (int) $id : 1;
            $this->reviewQueueHandler->handleReviewMenu($chatId, $user, $page);
            return;
        }

        if ($action === 'app') {
            $this->reviewQueueHandler->handleApprove($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'dsm') {
            $this->reviewQueueHandler->handleDismiss($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);
    }

    /**
     * Dispatch report callbacks.
     */
    protected function handleReportCallback(string $cqId, int $chatId, int $messageId, string $action, string $id, array $parts, ?User $user): void
    {
        if ($action === 'menu') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->reportHandler->handleMenu($chatId, $user);
            return;
        }

        if ($action === 'loc') {
            if ($id === 'stock') {
                $this->reportHandler->handleStockByLocation($cqId, $chatId, $messageId, $user);
            } elseif ($id === 'transit') {
                $this->reportHandler->handleInTransitReport($cqId, $chatId, $messageId, $user);
            } elseif ($id === 'discrepancies') {
                $this->reportHandler->handleDiscrepanciesReport($cqId, $chatId, $messageId, $user);
            }
            return;
        }

        if ($action === 'p') {
            $locId = $parts[3] ?? null;
            $this->reportHandler->handlePeriodReport($cqId, $chatId, $messageId, $id, $user, $locId);
            return;
        }

        if ($action === 'stock' && $id === 'val') {
            $this->reportHandler->handleStockValuation($cqId, $chatId, $messageId, $user);
            return;
        }

        if ($action === 'pay') {
            $this->reportHandler->showPaymentsAndDubeReport($cqId, $chatId, $messageId, $user);
            return;
        }

        if ($action === 'exp' && $id === 'menu') {
            $this->reportHandler->handleExportMenu($cqId, $chatId, $messageId, $user);
            return;
        }

        if ($action === 'exp') {
            $this->reportHandler->dispatchExport($cqId, $chatId, $id, $user, $parts);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);
    }

    /**
     * Dispatch team callbacks.
     */
    protected function handleTeamCallback(string $cqId, int $chatId, int $messageId, string $action, string $id, array $parts, ?User $user): void
    {
        if ($action === 'menu') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->teamHandler->handleTeamMenu($chatId, $user);
            return;
        }

        // Permission editor flows
        if (str_starts_with($action, 'ped_') || $action === 'ped') {
            if ($action === 'ped_start') {
                $this->teamHandler->startPermissionEditor($cqId, $chatId, $messageId, $id, $user);
            } elseif ($action === 'ped_role') {
                $role = $parts[3] ?? '';
                $this->teamHandler->selectPresetRole($cqId, $chatId, $messageId, $id, $role, $user);
            } elseif ($action === 'ped_togs') {
                $page = (int) ($parts[3] ?? 1);
                $this->teamHandler->viewFeatureToggles($cqId, $chatId, $messageId, $id, $page, $user);
            } elseif ($action === 'ped_tog') {
                $permKey = $parts[3] ?? '';
                $page = (int) ($parts[4] ?? 1);
                $this->teamHandler->toggleFeature($cqId, $chatId, $messageId, $id, $permKey, $page, $user);
            } elseif ($action === 'ped_locs') {
                $this->teamHandler->viewLocationAccess($cqId, $chatId, $messageId, $id, $user);
            } elseif ($action === 'ped_tloc') {
                $locId = $parts[3] ?? '';
                $this->teamHandler->toggleLocationAccess($cqId, $chatId, $messageId, $id, $locId, $user);
            } elseif ($action === 'ped_sum') {
                $this->teamHandler->viewPermissionSummary($cqId, $chatId, $messageId, $id, $user);
            } elseif ($action === 'ped_save') {
                $this->teamHandler->savePermissions($cqId, $chatId, $messageId, $id, $user);
            }
            return;
        }

        if ($action === 'invite' && $id === 'create') {
            $this->teamHandler->createInviteLink($cqId, $chatId, $messageId, $user);
            return;
        }

        if ($action === 'view') {
            $this->teamHandler->viewStaffMember($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'toggle_active') {
            $this->teamHandler->toggleStaffActive($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'remove') {
            $this->teamHandler->removeStaff($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'toggle' && $id === 'adjust') {
            $this->teamHandler->toggleStaffAdjust($cqId, $chatId, $messageId, $user);
            return;
        }

        if ($action === 'transfer_init') {
            $this->teamHandler->transferInit($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'transfer_confirm') {
            $this->teamHandler->transferConfirm($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'transfer_execute') {
            $this->teamHandler->transferExecute($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'log' && $id === 'view') {
            $this->teamHandler->viewActivityLogs($cqId, $chatId, $messageId, $user);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);
    }

    /**
     * Handle incoming chat message.
     */
    protected function handleMessage(array $message): void
    {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $from = $message['from'] ?? [];
        $text = trim((string) ($message['text'] ?? ''));

        if (empty($chatId) || $text === '') {
            return;
        }

        $user = $this->resolveUser($chatId);
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        // Check rate limit per-user (30 requests / minute)
        if ($this->isRateLimited($chatId)) {
            $this->telegram->sendMessage($chatId, __('rate_limited'));
            return;
        }

        // Universal Cancel: "❌ Cancel", "❌ ሰርዝ", "/cancel"
        if (in_array($text, ['❌ Cancel', '❌ ሰርዝ', '/cancel'], true)) {
            $this->botState->clear($chatId);
            $this->telegram->sendMessage(
                $chatId,
                __('action_cancelled'),
                ['reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user)]
            );
            return;
        }

        // Handle /start command (including /start pair_<code>)
        if (str_starts_with($text, '/start')) {
            $this->botState->clear($chatId);
            $cmdParts = explode(' ', $text, 2);
            $param = isset($cmdParts[1]) ? trim($cmdParts[1]) : null;
            $this->startHandler->handle($chatId, $from, $param, $user);
            return;
        }

        // Direct 6-digit numeric pairing code (e.g. "104442" or "104-442" or "/pair 104442")
        $digitsOnly = preg_replace('/[^0-9]/', '', $text);
        if (strlen($digitsOnly) === 6 && preg_match('/^(\/pair\s*)?[0-9]{3}-?[0-9]{3}$/i', $text)) {
            $this->botState->clear($chatId);
            $this->startHandler->handlePairStart((int) $chatId, $from, $digitsOnly, $user, $locale);
            return;
        }

        // Shortcut /pair command
        if (in_array($text, ['/pair', 'pair', 'አገናኝ', 'ስልክ አገናኝ'], true)) {
            $this->promptPairingCode($chatId, $user);
            return;
        }

        if (in_array(strtolower($text), ['/language', 'language', 'ቋንቋ'], true)) {
            $this->settingsHandler->handleSettings($chatId, $user);
            return;
        }

        if (in_array(strtolower($text), ['/deleteaccount', '/delete_shop', 'deleteaccount'], true)) {
            $this->startDeleteAccount($chatId, $user);
            return;
        }

        // Shortcut /devices command
        if (in_array($text, ['/devices', 'devices', 'ስልኮች', 'መሣሪያዎች'], true)) {
            $this->handleDevicesList($chatId, $user);
            return;
        }

        // Privacy Policy command
        if (in_array(strtolower($text), ['/privacy', 'privacy', 'የግላዊነት ፖሊሲ', 'ፖሊሲ'], true)) {
            $msg = $locale === 'en'
                ? "🔒 <b>Privacy Policy:</b>\nRead our full offline-first privacy policy at:\nhttps://stock.yenesuq.com/privacy/\n\nYour data stays on your device. Online sync stores only Telegram ID, device token, and synced shop ledger. You can delete your cloud data at any time via /delete_shop or in the app."
                : "🔒 <b>የግላዊነት ፖሊሲ፦</b>\nየመተግበሪያውን ሙሉ የግላዊነት መመሪያ በሚከተለው ሊንክ ይመልከቱ፦\nhttps://stock.yenesuq.com/privacy/\n\nመረጃዎ በስልክዎ ላይ ብቻ የሚቀመጥ ሲሆን፡ በመስመር ላይ የሚቀመጠው የቴሌግራም መለያዎ እና የመረጧቸው መረጃዎች ብቻ ናቸው። በማንኛውም ሰዓት በ /delete_shop ወይም በመተግበሪያው ላይ ማጥፋት ይችላሉ።";
            $this->telegram->sendMessage($chatId, $msg, ['parse_mode' => 'HTML']);
            return;
        }

        // Terms of Service command
        if (in_array(strtolower($text), ['/terms', 'terms', 'የአጠቃቀም ደንቦች', 'ደንቦች'], true)) {
            $msg = $locale === 'en'
                ? "📜 <b>Terms of Service:</b>\nRead our terms of service at:\nhttps://stock.yenesuq.com/terms/\n\nSupport contact: support@yenesuq.com"
                : "📜 <b>የአጠቃቀም ደንቦች፦</b>\nየአጠቃቀም ደንቦቹን በሚከተለው ሊንክ ይመልከቱ፦\nhttps://stock.yenesuq.com/terms/\n\nየድጋፍ አድራሻ፦ support@yenesuq.com";
            $this->telegram->sendMessage($chatId, $msg, ['parse_mode' => 'HTML']);
            return;
        }

        // Direct Download link command
        if (in_array(strtolower($text), ['/download', '/app', 'download', 'አውርድ', 'መተግበሪያ'], true)) {
            $msg = $locale === 'en'
                ? "📦 <b>Download Mobile App:</b>\nDownload the latest Android APK directly from our official portal:\nhttps://stock.yenesuq.com/app/\n\nAvailable for arm64-v8a, armeabi-v7a (32-bit), and Universal."
                : "📦 <b>የስልክ መተግበሪያውን ያውርዱ፦</b>\nየቅርብ ጊዜውን የአንድሮይድ መተግበሪያ (APK) ከኦፊሴላዊው ድረ-ገጻችን ያውርዱ፦\nhttps://stock.yenesuq.com/app/\n\nለ 64-bit፣ ለአሮጌ 32-bit እና ሁለንተናዊ (Universal) ስልኮች ዝግጁ ነው።";
            $this->telegram->sendMessage($chatId, $msg, ['parse_mode' => 'HTML']);
            return;
        }

        // Check active conversation state
        $state = $this->botState->get($chatId);
        if ($state !== null) {
            $this->handleStatefulInput($chatId, $text, $state, $user);
            return;
        }

        // Check for Quick-Add format: e.g. "Sugar 120 50" or "ጤፍ 150 200" or "Bread 25"
        if ($user?->role === 'owner' && preg_match('/^(.+?)\s+([0-9]+(?:\.[0-9]+)?)(?:\s+([0-9]+(?:\.[0-9]+)?))?$/u', $text, $matches)) {
            $nameCandidate = trim($matches[1]);
            $sellPrice = (float) $matches[2];
            $openingStock = isset($matches[3]) ? (float) $matches[3] : 0.0;

            if ($sellPrice > 0 && mb_strlen($nameCandidate) >= 2) {
                $this->productHandler->offerQuickAdd($chatId, $nameCandidate, $sellPrice, $openingStock, $user);
                return;
            }
        }

        // Fall through to menu handler (checks menu buttons and falls back to product search)
        $this->menuHandler->handle($chatId, $text, $user);
    }

    private function startDeleteAccount(int $chatId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);
        if ($user === null || $user->role !== 'owner') {
            $this->telegram->sendMessage($chatId, __('delete_account_owner_only'));
            return;
        }
        $this->telegram->sendMessage($chatId, __('delete_account_first'), [
            'reply_markup' => TelegramUi::inlineKeyboard([[
                ['text' => $locale === 'en' ? 'Continue' : 'ቀጥል', 'callback_data' => 'deleteaccount:second:0'],
                ['text' => $locale === 'en' ? 'Cancel' : 'ሰርዝ', 'callback_data' => 'deleteaccount:cancel:0'],
            ]]),
        ]);
    }

    private function handleDeleteAccountCallback(string $callbackId, int $chatId, string $action, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);
        $this->telegram->answerCallbackQuery($callbackId);
        if ($action === 'cancel') {
            $this->telegram->sendMessage($chatId, __('delete_account_cancelled'));
            return;
        }
        if ($user === null || $user->role !== 'owner') {
            $this->telegram->sendMessage($chatId, __('delete_account_owner_only'));
            return;
        }
        if ($action === 'second') {
            $shop = Shop::find($user->shop_id);
            $this->telegram->sendMessage($chatId, __('delete_account_second', ['shop' => $shop?->name ?? 'Shop']), [
                'reply_markup' => TelegramUi::inlineKeyboard([[
                    ['text' => $locale === 'en' ? 'Delete permanently' : 'በቋሚነት ሰርዝ', 'callback_data' => 'deleteaccount:final:0'],
                    ['text' => $locale === 'en' ? 'Cancel' : 'ሰርዝ', 'callback_data' => 'deleteaccount:cancel:0'],
                ]]),
            ]);
            return;
        }
        if ($action !== 'final') return;

        $shopId = (string) $user->shop_id;
        ImmediateTransaction::run(function () use ($shopId): void {
            Payment::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->delete();
            Sale::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->delete();
            Movement::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->delete();
            Product::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->delete();
            Customer::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->delete();
            Location::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->delete();
            PaymentProvider::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->delete();
            Change::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->delete();
            PairingCode::where('shop_id', $shopId)->delete();
            Device::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->delete();
            User::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->delete();
            Shop::where('id', $shopId)->delete();
        });
        $this->telegram->sendMessage($chatId, __('delete_account_done'));
    }

    /**
     * Dispatch text input to the active conversation state.
     */
    protected function handleStatefulInput(int $chatId, string $text, BotState $state, ?User $user): void
    {
        $locale = $user?->language ?? 'am';

        // 0. Pairing flow: awaiting code
        if ($state->flow === 'pairing' && $state->step === 'await_code') {
            $digits = preg_replace('/[^0-9]/', '', $text);
            if (strlen($digits) === 6) {
                $this->botState->clear($chatId);
                $this->startHandler->handlePairStart((int) $chatId, ['id' => $chatId], $digits, $user, $locale);
            } else {
                $this->telegram->sendMessage(
                    $chatId,
                    $locale === 'en'
                        ? "⚠️ Invalid code. Please enter the 6-digit numeric pairing code (e.g. 104442):"
                        : "⚠️ የተሳሳተ ኮድ። እባክዎ ባለ 6-አሃዝ ቁጥር ያስገቡ (ለምሳሌ 104442):",
                    ['reply_markup' => TelegramUi::cancelKeyboard($locale)]
                );
            }
            return;
        }

        // 1. Onboarding
        if ($state->flow === 'onboarding' && $state->step === 'await_shop_name') {
            $this->startHandler->handleShopNameInput($chatId, $text, $state->data ?? []);
            return;
        }

        // 2. Add Product
        if ($state->flow === 'add_product') {
            $this->productHandler->handleAddProductStep($chatId, $state->step, $text, $state->data ?? [], $user);
            return;
        }

        // 3. Stock In
        if ($state->flow === 'stock_in') {
            if ($state->step === 'await_qty') {
                $qty = TelegramUi::parseNumber($text);
                if ($qty !== null) {
                    $this->stockInHandler->handleQty($chatId, $qty, $user);
                } else {
                    $this->telegram->sendMessage($chatId, __('invalid_number'));
                }
                return;
            }

            if ($state->step === 'await_cost') {
                $cost = TelegramUi::parseNumber($text);
                $costCents = $cost !== null ? (int) round($cost * 100) : null;
                $this->stockInHandler->finalize($chatId, $costCents, $state->data, $user);
                return;
            }
        }

        // 4. Sale
        if ($state->flow === 'sale') {
            if ($state->step === 'await_qty') {
                $qty = TelegramUi::parseNumber($text);
                if ($qty !== null) {
                    $this->saleHandler->handleQuantitySelected($chatId, $qty, $user);
                } else {
                    $this->telegram->sendMessage($chatId, __('invalid_number'));
                }
                return;
            }

            if ($state->step === 'await_sale_ref') {
                $this->saleHandler->handleReferenceCompleted($chatId, $text, $user);
                return;
            }

            if ($state->step === 'await_custom_prov') {
                $this->saleHandler->handleCustomProviderEntered($chatId, $text, $user);
                return;
            }

            if ($state->step === 'await_dube_pnow') {
                $this->saleHandler->handleCustomPaidNowAmountEntered($chatId, $text, $user);
                return;
            }

            if ($state->step === 'await_dube_due') {
                $this->saleHandler->handleCustomDubeDueDateEntered($chatId, $text, $user);
                return;
            }

            if ($state->step === 'await_dube_search') {
                $this->saleHandler->handleDubeCustomerSearchEntered($chatId, $text, $user);
                return;
            }
        }

        // 4.5 Dube Flows
        if ($state->flow === 'dube_repay' && $state->step === 'await_amount') {
            $this->dubeHandler->handleCustomRepaymentAmountEntered($chatId, $text, $user);
            return;
        }

        if ($state->flow === 'dube_repay_ref' && $state->step === 'await_ref') {
            $this->dubeHandler->handleRepaymentReferenceEntered($chatId, $text, $user);
            return;
        }

        if ($state->flow === 'dube_ext_custom' && $state->step === 'await_date') {
            $this->dubeHandler->handleCustomDueDateEntered($chatId, $text, $user);
            return;
        }

        if ($state->flow === 'dube_add_cust') {
            if ($state->step === 'await_name') {
                $this->dubeHandler->handleCustomerNameEntered($chatId, $text, $user);
                return;
            }
            if ($state->step === 'await_phone') {
                $this->dubeHandler->handleCustomerPhoneEntered($chatId, $text, $user);
                return;
            }
        }

        if ($state->flow === 'dube_edit_cust') {
            if ($state->step === 'await_name') {
                $this->dubeHandler->handleEditCustomerNameEntered($chatId, $text, $user);
                return;
            }
            if ($state->step === 'await_phone') {
                $this->dubeHandler->handleEditCustomerPhoneEntered($chatId, $text, $user);
                return;
            }
        }

        if ($state->flow === 'dube_search' && $state->step === 'await_query') {
            $this->dubeHandler->handleCustomerSearch($chatId, $text, $user);
            return;
        }

        // 5. Adjust
        if ($state->flow === 'adjust' && $state->step === 'await_qty') {
            $qty = TelegramUi::parseNumber($text);
            if ($qty !== null) {
                $this->adjustHandler->handleCountedQty($chatId, $qty, $user);
            } else {
                $this->telegram->sendMessage($chatId, __('invalid_number'));
            }
            return;
        }

        // 6. Edit Price
        if ($state->flow === 'edit_price' && $state->step === 'await_price') {
            $price = TelegramUi::parseNumber($text);
            if ($price !== null && $price >= 0) {
                $this->productHandler->handleNewPriceInput($chatId, $price, $state->data ?? [], $user);
            } else {
                $this->telegram->sendMessage($chatId, __('invalid_number'));
            }
            return;
        }

        // 7. Transfer quantity
        if ($state->flow === 'transfer' && $state->step === 'await_qty') {
            $qty = TelegramUi::parseNumber($text);
            if ($qty !== null && $qty > 0) {
                $this->transferHandler->handleQuantityEntered($chatId, (float) $qty, $state, $user);
            } else {
                $this->telegram->sendMessage($chatId, __('invalid_number'));
            }
            return;
        }

        // 8. Transfer different quantity entry
        if ($state->flow === 'transfer_diff' && $state->step === 'await_diff_qty') {
            $qty = TelegramUi::parseNumber($text);
            if ($qty !== null && $qty >= 0) {
                $this->transferHandler->handleDifferentQtyEntered($chatId, (float) $qty, $state, $user);
            } else {
                $this->telegram->sendMessage($chatId, __('invalid_number'));
            }
            return;
        }

        // 9. Rename location
        if ($state->flow === 'loc_rename' && $state->step === 'await_name') {
            $this->locationHandler->handleRenameEntered($chatId, $text, $state->data ?? [], $user);
            return;
        }

        // Default: clear unrecognized state and pass to menu
        $this->botState->clear($chatId);
        $this->menuHandler->handle($chatId, $text, $user);
    }

    /**
     * Resolve User model by telegram_id without shop scope.
     */
    protected function resolveUser(int $telegramId): ?User
    {
        return User::withoutGlobalScope(ShopScope::class)
            ->where('telegram_id', $telegramId)
            ->first();
    }

    /**
     * Check if user exceeded rate limit (30 requests/min).
     */
    protected function isRateLimited(int $chatId): bool
    {
        $rateKey = "tg_user_rate:{$chatId}";
        if (RateLimiter::tooManyAttempts($rateKey, 30)) {
            return true;
        }

        RateLimiter::hit($rateKey, 60);
        return false;
    }
}
