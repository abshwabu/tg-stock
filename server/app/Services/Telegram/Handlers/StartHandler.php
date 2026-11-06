<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Context\CurrentShopContext;
use App\Models\PairingCode;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\Telegram\BotStateService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use Illuminate\Support\Str;

class StartHandler
{
    public function __construct(
        protected TelegramClient $telegram,
        protected BotStateService $botState,
        protected TeamHandler $teamHandler
    ) {}

    /**
     * Handle the /start command, with optional parameter (e.g. "pair_123456" or "join_abcdef").
     */
    public function handle(int|string $chatId, array $from, ?string $param = null, ?User $user = null): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        // 1. Check if start parameter is a team join token: /start join_<token>
        if (!empty($param) && str_starts_with($param, 'join_')) {
            $token = substr($param, 5);
            $this->teamHandler->handleJoinInvite($chatId, $from, $token);
            return;
        }

        // 2. Check if start parameter is an app pairing code: /start pair_<code>
        if (!empty($param) && str_starts_with($param, 'pair_')) {
            $code = substr($param, 5);
            $this->handlePairStart((int) $chatId, $from, $code, $user, $locale);
            return;
        }

        // 2. Existing user with a registered shop
        if ($user !== null && !empty($user->shop_id)) {
            $shop = Shop::find($user->shop_id);
            $shopName = $shop?->name ?? 'Shop';

            $this->telegram->sendMessage(
                $chatId,
                __('welcome_existing', ['name' => $user->name, 'shop' => $shopName]),
                ['reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user)]
            );

            $this->telegram->sendMessage(
                $chatId,
                $locale === 'en' ? '📋 <b>Main Menu:</b>' : '📋 <b>ዋና ማውጫ:</b>',
                ['reply_markup' => TelegramUi::mainMenuInlineKeyboard($locale, $user)]
            );
            $this->telegram->sendMessage($chatId, __('help_text'));
            return;
        }

        // 3. New user onboarding: ask shop name
        $this->botState->start($chatId, 'onboarding', 'await_shop_name', [
            'from' => $from,
            'language' => $locale,
        ]);

        $this->telegram->sendMessage(
            $chatId,
            __('onboarding_ask_shop_name'),
            ['reply_markup' => TelegramUi::cancelKeyboard($locale)]
        );
    }

    /**
     * Handle input during onboarding step 'await_shop_name'.
     */
    public function handleShopNameInput(int|string $chatId, string $shopName, array $stateData): void
    {
        $locale = $stateData['language'] ?? 'am';
        app()->setLocale($locale);

        $trimmed = trim($shopName);
        if (mb_strlen($trimmed) < 2) {
            $this->telegram->sendMessage(
                $chatId,
                __('onboarding_invalid_name'),
                ['reply_markup' => TelegramUi::cancelKeyboard($locale)]
            );
            return;
        }

        // Create new shop
        $shop = Shop::create([
            'id' => (string) Str::uuid7(),
            'name' => $trimmed,
            'currency' => 'ETB',
            'language' => $locale,
            'low_stock_default_milli' => 5000,
        ]);

        $from = $stateData['from'] ?? [];
        $userName = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
        if (empty($userName)) {
            $userName = $from['username'] ?? 'Shop Owner';
        }

        // Create owner user within tenant context
        CurrentShopContext::runWith($shop, function () use ($shop, $chatId, $userName, $locale): void {
            User::withoutGlobalScope(ShopScope::class)->updateOrCreate(
                ['telegram_id' => (int) $chatId],
                [
                    'id' => (string) Str::uuid7(),
                    'name' => $userName,
                    'language' => $locale,
                    'shop_id' => $shop->id,
                    'role' => 'owner',
                    'is_active' => true,
                ]
            );
        });

        // Clear conversation state
        $this->botState->clear($chatId);

        $ownerUser = User::withoutGlobalScope(ShopScope::class)->where('telegram_id', (int) $chatId)->first();

        $this->telegram->sendMessage(
            $chatId,
            __('onboarding_shop_created', ['name' => $shop->name]),
            ['reply_markup' => TelegramUi::mainMenuKeyboard($locale, $ownerUser)]
        );

        $this->telegram->sendMessage(
            $chatId,
            $locale === 'en' ? '📋 <b>Main Menu:</b>' : '📋 <b>ዋና ማውጫ:</b>',
            ['reply_markup' => TelegramUi::mainMenuInlineKeyboard($locale, $ownerUser)]
        );
        $this->telegram->sendMessage($chatId, __('help_text'));
    }

    /**
     * Initiate phone pairing prompt: "Link this phone to <shop>?".
     */
    public function handlePairStart(int $chatId, array $from, string $code, ?User $user, string $locale): void
    {
        $codeHash = hash('sha256', trim($code));
        $pairing = PairingCode::where('code_hash', $codeHash)->first();

        if ($pairing === null || $pairing->isExpired()) {
            $this->telegram->sendMessage(
                $chatId,
                __('pair_invalid_code'),
                ['reply_markup' => TelegramUi::mainMenuKeyboard($locale)]
            );
            return;
        }

        // Determine shop name to display
        $shopName = 'Your Shop';
        if ($user !== null && !empty($user->shop_id)) {
            $shop = Shop::find($user->shop_id);
            if ($shop) {
                $shopName = $shop->name;
            }
        } elseif (!empty($pairing->shop_name)) {
            $shopName = $pairing->shop_name;
        } else {
            $shopName = ($from['first_name'] ?? 'My') . ' Shop';
        }

        $prompt = __('pair_ask_confirmation', [
            'device' => $pairing->device_name,
            'shop' => $shopName,
        ]);

        $keyboard = TelegramUi::confirmKeyboard(
            "pair:confirm:{$code}",
            "pair:cancel:{$code}",
            $locale
        );

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => $keyboard,
        ]);
    }
}
