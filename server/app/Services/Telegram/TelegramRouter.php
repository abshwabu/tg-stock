<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\BotState;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\PermissionService;
use App\Services\Telegram\Handlers\AdjustHandler;
use App\Services\Telegram\Handlers\LocationHandler;
use App\Services\Telegram\Handlers\MenuHandler;
use App\Services\Telegram\Handlers\PairingHandler;
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
            $this->handleSaleCallback($cqId, $chatId, $messageId, $action, $id, $user);
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
    protected function handleSaleCallback(string $cqId, int $chatId, int $messageId, string $action, string $id, ?User $user): void
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
                'reply_markup' => TelegramUi::mainMenuKeyboard($user?->language ?? 'am'),
            ]);
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

        // Shortcut /devices command
        if (in_array($text, ['/devices', 'devices', 'ስልኮች', 'መሣሪያዎች'], true)) {
            $this->handleDevicesList($chatId, $user);
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
        if ($state->flow === 'sale' && $state->step === 'await_qty') {
            $qty = TelegramUi::parseNumber($text);
            if ($qty !== null) {
                $this->saleHandler->handleQuantitySelected($chatId, $qty, $user);
            } else {
                $this->telegram->sendMessage($chatId, __('invalid_number'));
            }
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
