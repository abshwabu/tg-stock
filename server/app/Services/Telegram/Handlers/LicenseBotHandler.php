<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\Shop;
use App\Models\ShopLicense;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\CrockfordBase32;
use App\Services\DateEpochService;
use App\Services\HmacService;
use App\Services\LicenseEnforcer;
use App\Services\LicenseVerifier;
use App\Services\Telegram\BotStateService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use App\Support\EthiopicCalendar;
use Illuminate\Support\Facades\Log;

class LicenseBotHandler
{
    public function __construct(
        protected TelegramClient $telegram,
        protected BotStateService $botState,
        protected LicenseEnforcer $licenseEnforcer
    ) {}

    /**
     * Show License status screen from Settings -> License.
     */
    public function handleViewLicense(int|string $chatId, ?User $user, ?int $messageId = null): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $shop = $user?->shop_id ? Shop::find($user->shop_id) : null;
        if ($shop === null) {
            $this->telegram->sendMessage($chatId, $locale === 'en'
                ? "No shop is currently associated with this account."
                : "ከዚህ አካውንት ጋር የተገናኘ ሱቅ አልተገኘም።");
            return;
        }

        $eval = $this->licenseEnforcer->evaluateShopLicense($shop);
        $shopCode = $shop->getShopCode();

        $state = $eval['state'];
        $seatsUsed = $eval['seats_used'];
        $seatsTotal = $eval['seats_total'];
        $expDay = $eval['exp_day'];
        $daysLeft = $eval['days_left'];

        // Format state label
        $stateLabel = match ($state) {
            'ACTIVE' => '✅ ACTIVE (ንቁ)',
            'EXPIRING' => "⚠️ EXPIRING ({$daysLeft} days left / {$daysLeft} ቀናት ቀርተዋል)",
            'GRACE' => "⏳ GRACE PERIOD ({$daysLeft} days grace / የችሮታ ጊዜ)",
            'EXPIRED' => '❌ EXPIRED (ጊዜው አልፎበታል)',
            'SUSPENDED' => '⛔ SUSPENDED (የታገደ)',
            'REVOKED' => '🚫 REVOKED (የተሰረዘ)',
            default => '❓ UNLICENSED (ያልነቃ)',
        };

        // Format Expiry with Ethiopian Calendar toggle support
        $expiryStr = 'Lifetime (የዕድሜ ልክ)';
        if ($expDay !== null && $expDay !== 0xFFFF) {
            $expDate = DateEpochService::dayToDate($expDay);
            $gregorian = $expDate->format('Y-m-d');
            $ethiopian = EthiopicCalendar::format($expDate, $locale);

            if ($shop->use_ethiopian_calendar) {
                $expiryStr = "🇪🇹 <b>{$ethiopian}</b> ({$gregorian})";
            } else {
                $expiryStr = "🌐 <b>{$gregorian}</b> ({$ethiopian})";
            }
        }

        if ($locale === 'en') {
            $text = "🔑 <b>License & Subscription Details</b>\n\n"
                  . "🏪 <b>Shop:</b> {$shop->name}\n"
                  . "📌 <b>Status:</b> {$stateLabel}\n"
                  . "📅 <b>Expiry:</b> {$expiryStr}\n"
                  . "📱 <b>Seats Used:</b> {$seatsUsed} / {$seatsTotal} devices\n"
                  . "🏷️ <b>Shop Code:</b> <code>{$shopCode}</code>\n\n"
                  . "<i>Tap the Shop Code to copy when ordering or renewing.</i>";
        } else {
            $text = "🔑 <b>የፈቃድ እና ምዝገባ ዝርዝር</b>\n\n"
                  . "🏪 <b>ሱቅ:</b> {$shop->name}\n"
                  . "📌 <b>ሁኔታ:</b> {$stateLabel}\n"
                  . "📅 <b>የሚያበቃበት:</b> {$expiryStr}\n"
                  . "📱 <b>የተገናኙ ስልኮች:</b> {$seatsUsed} / {$seatsTotal}\n"
                  . "🏷️ <b>የሱቅ ኮድ:</b> <code>{$shopCode}</code>\n\n"
                  . "<i>የሱቅ ኮዱን በመንካት ኮፒ ማድረግ ይችላሉ።</i>";
        }

        $buttons = [
            [
                ['text' => $locale === 'en' ? '💳 Buy / Renew' : '💳 ግዛ / አድስ', 'callback_data' => 'license:buy:0'],
                ['text' => $locale === 'en' ? '🎁 Free Trial' : '🎁 ነጻ ሙከራ', 'callback_data' => 'license:trial:0'],
            ],
            [
                ['text' => $locale === 'en' ? '✏️ Enter Token' : '✏️ ቶከን አስገባ', 'callback_data' => 'license:enter:0'],
                ['text' => $locale === 'en' ? '📅 Toggle Calendar' : '📅 ቀን ቀይር', 'callback_data' => 'license:toggle_cal:0'],
            ],
            [
                ['text' => $locale === 'en' ? '🔙 Back to Settings' : '🔙 ወደ ቅንብሮች ተመለስ', 'callback_data' => 'settings:back:0'],
            ],
        ];

        $replyMarkup = TelegramUi::inlineKeyboard($buttons);

        if ($messageId) {
            $this->telegram->editMessageText($chatId, $messageId, $text, [
                'reply_markup' => $replyMarkup,
            ]);
        } else {
            $this->telegram->sendMessage($chatId, $text, [
                'reply_markup' => $replyMarkup,
            ]);
        }
    }

    /**
     * Show Plan selection menu for Buy/Renew.
     */
    public function handleBuyMenu(int|string $chatId, ?User $user, ?string $targetCode = null, ?int $messageId = null): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $plans = config('license.plans', []);
        $codeParam = $targetCode ?: 'self';

        $title = $locale === 'en'
            ? "💳 <b>Select a License Plan:</b>\n\nChoose the duration and plan that suits your business:"
            : "💳 <b>የፈቃድ ዕቅድ ይምረጡ፦</b>\n\nለሱቅዎ የሚስማማውን የጊዜ ርዝማኔ ይምረጡ፦";

        $rows = [];
        foreach ($plans as $planId => $p) {
            $name = ($locale === 'en' ? $p['name_en'] : $p['name_am']);
            $price = number_format($p['price_etb']) . ' ETB';
            $rows[] = [
                ['text' => "{$name} — {$price}", 'callback_data' => "license:plan:{$planId}:{$codeParam}"],
            ];
        }

        $rows[] = [
            ['text' => $locale === 'en' ? '🔙 Cancel' : '🔙 ተመለስ', 'callback_data' => 'settings:license:view'],
        ];

        $replyMarkup = TelegramUi::inlineKeyboard($rows);

        if ($messageId) {
            $this->telegram->editMessageText($chatId, $messageId, $title, ['reply_markup' => $replyMarkup]);
        } else {
            $this->telegram->sendMessage($chatId, $title, ['reply_markup' => $replyMarkup]);
        }
    }

    /**
     * Show payment instructions after plan is selected.
     */
    public function handlePlanSelected(int|string $chatId, ?User $user, string $planId, string $targetCode, ?int $messageId = null): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $plans = config('license.plans', []);
        $plan = $plans[$planId] ?? ($plans['monthly'] ?? []);
        $planName = $locale === 'en' ? ($plan['name_en'] ?? 'Monthly Plan') : ($plan['name_am'] ?? 'የወር ፈቃድ');
        $price = number_format($plan['price_etb'] ?? 500) . ' ETB';

        $resolvedCode = ($targetCode === 'self' || empty($targetCode))
            ? ($user?->shop?->getShopCode() ?? '')
            : $targetCode;

        // Stage bot state
        $this->botState->set((int)$chatId, 'awaiting_payment_proof', [
            'plan_id' => $planId,
            'code' => $resolvedCode,
            'price_etb' => $plan['price_etb'] ?? 500,
        ]);

        $telebirr = config('license.payment_instructions.telebirr', '0911234567');
        $cbe = config('license.payment_instructions.cbe', '1000123456789');
        $accountName = config('license.payment_instructions.account_name', 'TGStock Technologies PLC');

        if ($locale === 'en') {
            $text = "💳 <b>Payment Instructions — {$planName}</b>\n\n"
                  . "<b>Amount to Pay:</b> {$price}\n"
                  . "<b>For Identifier:</b> <code>{$resolvedCode}</code>\n\n"
                  . "Please transfer the amount using one of the following methods:\n\n"
                  . "📱 <b>Telebirr:</b> <code>{$telebirr}</code>\n"
                  . "🏦 <b>CBE Account:</b> <code>{$cbe}</code>\n"
                  . "👤 <b>Account Name:</b> {$accountName}\n\n"
                  . "━━━━━━━━━━━━━━━━━━━━\n"
                  . "📤 <b>Next Step:</b>\n"
                  . "Please <b>reply to this message with the transaction reference number</b> (e.g. <i>CBE12345678</i>) or <b>upload a screenshot/photo</b> of the payment receipt.";
        } else {
            $text = "💳 <b>የክፍያ መመሪያ — {$planName}</b>\n\n"
                  . "<b>የሚከፈለው ገንዘብ:</b> {$price}\n"
                  . "<b>የመለያ ኮድ:</b> <code>{$resolvedCode}</code>\n\n"
                  . "እባክዎ ክፍያውን በሚከተሉት መንገዶች ይፈጽሙ፦\n\n"
                  . "📱 <b>ቴሌብር:</b> <code>{$telebirr}</code>\n"
                  . "🏦 <b>ንግድ ባንክ (CBE):</b> <code>{$cbe}</code>\n"
                  . "👤 <b>የሂሳብ ስም:</b> {$accountName}\n\n"
                  . "━━━━━━━━━━━━━━━━━━━━\n"
                  . "📤 <b>ቀጣይ እርምጃ፦</b>\n"
                  . "ክፍያውን ከፈጸሙ በኋላ <b>የደረሰኙን የግብይት ቁጥር (Transaction Reference) ይጻፉልን</b> ወይም <b>የደረሰኙን ፎቶ / Screenshot ይላኩ</b>።";
        }

        $buttons = [
            [['text' => $locale === 'en' ? '❌ Cancel' : '❌ ሰርዝ', 'callback_data' => 'settings:license:view']],
        ];

        if ($messageId) {
            $this->telegram->editMessageText($chatId, $messageId, $text, [
                'reply_markup' => TelegramUi::inlineKeyboard($buttons),
            ]);
        } else {
            $this->telegram->sendMessage($chatId, $text, [
                'reply_markup' => TelegramUi::inlineKeyboard($buttons),
            ]);
        }
    }

    /**
     * Process payment proof received (text reference or photo).
     */
    public function handlePaymentProofReceived(int|string $chatId, ?User $user, string $reference, ?string $photoFileId = null): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $state = $this->botState->get((int)$chatId);
        $stateData = $state?->data ?? [];

        $planId = $stateData['plan_id'] ?? 'monthly';
        $code = $stateData['code'] ?? ($user?->shop?->getShopCode() ?? '');

        // Privacy: only Telegram ID, name, phone, plan choice, code, and payment reference sent to console
        $res = HmacService::sendToConsole('POST', '/api/v1/payment-requests', [
            'telegram_id' => $chatId,
            'name' => $user?->name ?? 'Telegram User',
            'phone' => $user?->phone ?? '',
            'plan_id' => $planId,
            'code' => $code,
            'reference' => $photoFileId ? "Photo receipt (Telegram file_id: {$photoFileId}) Ref: {$reference}" : $reference,
        ]);

        $this->botState->clear((int)$chatId);

        if ($locale === 'en') {
            $text = "✅ <b>Payment Request Submitted!</b>\n\n"
                  . "Your payment reference (<code>{$reference}</code>) has been submitted for verification.\n\n"
                  . "Once approved by administration, your license token will be issued and delivered to you here in Telegram automatically.";
        } else {
            $text = "✅ <b>የክፍያ ማረጋገጫው ተልኳል!</b>\n\n"
                  . "የክፍያ ቁጥርዎ (<code>{$reference}</code>) ለአስተዳዳሪው ተልኳል።\n\n"
                  . "ክፍያው እንደተረጋገጠ አዲሱ የፈቃድ ቁጥርዎ በራስ-ሰር እዚህ ይላክልዎታል።";
        }

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user),
        ]);
    }

    /**
     * Prompt user to manually paste a token.
     */
    public function handleEnterTokenPrompt(int|string $chatId, ?User $user, ?int $messageId = null): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $this->botState->set((int)$chatId, 'awaiting_license_token', []);

        $text = $locale === 'en'
            ? "✏️ <b>Enter License Token:</b>\n\nPlease paste your <code>LIC1-XXXXX-...</code> license token string into the chat:"
            : "✏️ <b>የፈቃድ ቁጥር (Token) ያስገቡ፦</b>\n\nእባክዎ የፈቃድ ቁጥርዎን (<code>LIC1-XXXXX-...</code>) ኮፒ አድርገው እዚህ ይላኩ፦";

        $buttons = [
            [['text' => $locale === 'en' ? '❌ Cancel' : '❌ ሰርዝ', 'callback_data' => 'settings:license:view']],
        ];

        if ($messageId) {
            $this->telegram->editMessageText($chatId, $messageId, $text, [
                'reply_markup' => TelegramUi::inlineKeyboard($buttons),
            ]);
        } else {
            $this->telegram->sendMessage($chatId, $text, [
                'reply_markup' => TelegramUi::inlineKeyboard($buttons),
            ]);
        }
    }

    /**
     * Handle manual token submitted by user in chat.
     */
    public function handleTokenSubmitted(int|string $chatId, ?User $user, string $tokenString): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $this->botState->clear((int)$chatId);

        $shop = $user?->shop_id ? Shop::find($user->shop_id) : null;
        if ($shop === null) {
            $this->telegram->sendMessage($chatId, "No shop associated with this account.");
            return;
        }

        if ($user->role !== 'owner') {
            $this->telegram->sendMessage($chatId, $locale === 'en'
                ? "Only shop owners can activate license tokens."
                : "የሱቁ ባለቤት ብቻ ነው ፈቃድ ማስገባት የሚችለው።");
            return;
        }

        $verification = LicenseVerifier::verify(
            tokenString: $tokenString,
            expectedType: 2, // Must be shop-bound
            expectedBind: $shop->getBindBytes()
        );

        if ($verification['state'] === LicenseVerifier::STATE_WRONG_BIND) {
            $msg = $locale === 'en'
                ? "❌ <b>Wrong Shop:</b> This license token is bound to a different shop code."
                : "❌ <b>ስህተት፦</b> ይህ የፈቃድ ቁጥር ለሌላ ሱቅ የተሰጠ ነው።";
            $this->telegram->sendMessage($chatId, $msg);
            return;
        }

        if (!$verification['is_valid'] && !in_array($verification['state'], ['EXPIRING', 'GRACE', 'EXPIRED'], true)) {
            $msg = $locale === 'en'
                ? "❌ <b>Invalid Token:</b> " . ($verification['reason'] ?? 'Signature verification failed.')
                : "❌ <b>ልክ ያልሆነ ፈቃድ፦</b> " . ($verification['reason'] ?? 'የተሳሳተ ቶከን።');
            $this->telegram->sendMessage($chatId, $msg);
            return;
        }

        $payload = $verification['payload'];

        ShopLicense::updateOrCreate(
            ['shop_id' => $shop->id],
            [
                'token' => $tokenString,
                'license_id' => $payload['license_id_hex'] ?? null,
                'plan' => $payload['plan'] ?? null,
                'exp_day' => $payload['exp_day'] ?? null,
                'grace_days' => $payload['grace_days'] ?? 7,
                'seats' => $payload['seats'] ?? 1,
                'features' => $payload['features'] ?? 0,
                'status' => strtolower($verification['state']),
            ]
        );

        $successText = $locale === 'en'
            ? "🎉 <b>License Activated Successfully!</b>\n\nYour shop is now licensed and operating in full mode."
            : "🎉 <b>ፈቃድዎ በተሳካ ሁኔታ ነቅቷል!</b>\n\nሱቅዎ አሁን በሙሉ አገልግሎት እየሰራ ይገኛል።";

        $this->telegram->sendMessage($chatId, $successText, [
            'reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user),
        ]);
    }

    /**
     * Handle free 14-day trial request (/trial or button).
     */
    public function handleTrialRequest(int|string $chatId, ?User $user, ?string $code = null, ?int $messageId = null): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $shop = $user?->shop_id ? Shop::find($user->shop_id) : null;
        $targetCode = $code ?: ($shop ? $shop->getShopCode() : null);

        if (!$targetCode) {
            $this->telegram->sendMessage($chatId, $locale === 'en'
                ? "Please provide your device code to start a trial: e.g. <code>/trial DXXX-XXXX-XXXX-XXX</code>"
                : "ነጻ ሙከራ ለመጀመር እባክዎ የስልክዎን ኮድ ያስገቡ፦ ምሳሌ <code>/trial DXXX-XXXX-XXXX-XXX</code>");
            return;
        }

        // Call console API /api/v1/trials
        $res = HmacService::sendToConsole('POST', '/api/v1/trials', [
            'telegram_id' => $chatId,
            'code' => $targetCode,
        ]);

        if (!$res['success']) {
            $error = $res['data']['error'] ?? 'Free trial request failed.';
            $msg = $locale === 'en'
                ? "❌ <b>Trial Unavailable:</b> {$error}"
                : "❌ <b>ነጻ ሙከራው አልተሳካም፦</b> ይህ አካውንት ወይም መሣሪያ ቀደም ሲል ነጻ ሙከራ ወስዷል።";
            $this->telegram->sendMessage($chatId, $msg);
            return;
        }

        $token = $res['data']['token'] ?? null;
        $expDay = $res['data']['exp_day'] ?? null;

        if ($shop !== null && $token && str_starts_with(strtoupper($targetCode), 'S')) {
            // Apply shop trial directly
            $verification = LicenseVerifier::verify($token, expectedType: 2, expectedBind: $shop->getBindBytes());
            $p = $verification['payload'];

            ShopLicense::updateOrCreate(
                ['shop_id' => $shop->id],
                [
                    'token' => $token,
                    'license_id' => $p['license_id_hex'] ?? null,
                    'plan' => 0, // Trial
                    'exp_day' => $p['exp_day'] ?? $expDay,
                    'grace_days' => 7,
                    'seats' => $p['seats'] ?? 3,
                    'features' => 0x001F,
                    'status' => 'active',
                ]
            );
        }

        if ($locale === 'en') {
            $text = "🎉 <b>14-Day Free Trial Activated!</b>\n\n"
                  . "Your trial is now active.\n\n"
                  . "<b>Token:</b>\n<code>{$token}</code>\n\n"
                  . "<i>If you are using an offline device, tap the token above to copy and enter it in Settings → License.</i>";
        } else {
            $text = "🎉 <b>የ14 ቀን ነጻ ሙከራ ነቅቷል!</b>\n\n"
                  . "ነጻ ሙከራዎ አሁን ዝግጁ ነው።\n\n"
                  . "<b>የፈቃድ ቁጥር (Token):</b>\n<code>{$token}</code>\n\n"
                  . "<i>ኦፍላይን የሚጠቀሙ ከሆነ ቶከኑን ኮፒ በማድረግ በስልክዎ መተግበሪያ ቅንብሮች -> ፈቃድ ውስጥ ያስገቡ።</i>";
        }

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user),
        ]);
    }

    /**
     * Handle offline-only customer /activate <device code> or /start code_<device code>.
     */
    public function handleOfflineCodeStart(int|string $chatId, array $from, string $rawCode, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        try {
            $parsed = CrockfordBase32::parseCode($rawCode);
            $formattedCode = $parsed['formatted'];
        } catch (\Throwable $e) {
            $this->telegram->sendMessage($chatId, $locale === 'en'
                ? "❌ Invalid device code: " . $e->getMessage()
                : "❌ የተሳሳተ የመሣሪያ ኮድ፦ " . $e->getMessage());
            return;
        }

        if ($locale === 'en') {
            $text = "📱 <b>Offline Device Activation</b>\n\n"
                  . "<b>Device Code:</b> <code>{$formattedCode}</code>\n\n"
                  . "Choose an option to activate your offline POS device:";
        } else {
            $text = "📱 <b>የመሣሪያ ፈቃድ ማስነሻ (Offline POS)</b>\n\n"
                  . "<b>የመሣሪያ ኮድ:</b> <code>{$formattedCode}</code>\n\n"
                  . "ፈቃድ ለማስነሳት ከሚከተሉት አማራጮች አንዱን ይምረጡ፦";
        }

        $buttons = [
            [
                ['text' => $locale === 'en' ? '💳 Buy License' : '💳 ፈቃድ ግዛ', 'callback_data' => "license:buy:code:{$parsed['formatted']}"],
                ['text' => $locale === 'en' ? '🎁 14-Day Free Trial' : '🎁 የ14 ቀን ነጻ ሙከራ', 'callback_data' => "license:trial:code:{$parsed['formatted']}"],
            ],
        ];

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Send license renewal block notice when write action is blocked.
     */
    public function sendRenewalBlockNotice(int|string $chatId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $isOwner = ($user?->role === 'owner');

        if (!$isOwner) {
            $staffNotice = $locale === 'en'
                ? "⚠️ <b>License expired — ask the owner to renew.</b>"
                : "⚠️ <b>ፈቃዱ ጊዜው አልፎበታል — እባክዎ ባለቤቱን እንዲያድስ ይጠይቁ።</b>";
            $this->telegram->sendMessage($chatId, $staffNotice);
            return;
        }

        if ($locale === 'en') {
            $text = "⚠️ <b>License Expired — Read-Only Mode</b>\n\n"
                  . "Your shop license has expired and the shop is currently in Read-Only mode.\n"
                  . "Recording new sales, stock movements, and products is blocked until renewed.\n\n"
                  . "<i>Your historical records, reports, and debtor lists remain safe and fully accessible.</i>";
        } else {
            $text = "⚠️ <b>የሱቅ ፈቃድ ጊዜው አልፎበታል (ማንበብ ብቻ)</b>\n\n"
                  . "የሱቅዎ ፈቃድ ጊዜው ስላለፈበት ሱቁ ወደ ማንበብ ብቻ (Read-Only) ሁነታ ተቀይሯል።\n"
                  . "አዳዲስ ሽያጮችን፣ ዕቃዎችን እና የዋጋ ለውጦችን ለመመዝገብ እባክዎ ፈቃዱን ያድሱ።\n\n"
                  . "<i>የቀድሞ መረጃዎችዎ፣ ሪፖርቶች እና የዱቤ ዝርዝር ሳይነኩ ደህንነታቸው እንደተጠበቀ ነው።</i>";
        }

        $buttons = [
            [['text' => $locale === 'en' ? '💳 Buy / Renew License' : '💳 ፈቃድ አድስ', 'callback_data' => 'license:buy:0']],
        ];

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Send notice explaining that a specific premium feature requires an upgraded license or trial.
     */
    public function sendFeatureRequiredNotice(int|string $chatId, ?User $user, string $feature, ?int $messageId = null): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $featureLabel = match ($feature) {
            'team_roles' => ($locale === 'en' ? 'Team & Staff Management' : 'የቡድን እና ሠራተኞች አስተዳደር'),
            'multi_location' => ($locale === 'en' ? 'Multi-Location & Transfers' : 'የቦታዎች እና ዝውውር አስተዳደር'),
            'online_sync' => ($locale === 'en' ? 'Online Cloud Sync' : 'የመስመር ላይ ማመሳሰል'),
            'export' => ($locale === 'en' ? 'PDF/Excel Export' : 'መረጃ ኤክስፖርት ማድረግ'),
            'telegram_bot' => ($locale === 'en' ? 'Telegram Bot Access' : 'የቴሌግራም ቦት አገልግሎት'),
            default => ($locale === 'en' ? 'Premium Feature' : 'ልዩ አገልግሎት'),
        };

        if ($locale === 'en') {
            $text = "👥 <b>{$featureLabel}</b>\n\n"
                  . "This feature is not included in your shop's current license plan or status.\n\n"
                  . "To unlock team management, staff permissions, and role assignment:\n"
                  . "• Start a <b>14-Day Free Trial</b> (includes all features)\n"
                  . "• Or upgrade to a <b>Pro / Master Plan</b>\n\n"
                  . "<i>Staff devices are covered under your shop license at no extra cost.</i>";
        } else {
            $text = "👥 <b>{$featureLabel}</b>\n\n"
                  . "ይህ አገልግሎት አሁን ባለው የሱቅዎ ፈቃድ ውስጥ አልተካተተም ወይም ፈቃድ አልነቃም።\n\n"
                  . "የሠራተኞች አስተዳደር፣ ፈቃዶችን መቆጣጠር እና ሚናዎችን ለመመደብ፦\n"
                  . "• የ <b>14 ቀን ነጻ ሙከራ</b> ይጀምሩ (ሁሉንም ያካትታል)\n"
                  . "• ወይም የ <b>Pro / Master</b> ፈቃድ ያግኙ\n\n"
                  . "<i>የሠራተኞች ስልኮች በሱቁ ፈቃድ ያለምንም ተጨማሪ ክፍያ ይሸፈናሉ።</i>";
        }

        $buttons = [
            [
                ['text' => $locale === 'en' ? '🎁 Start 14-Day Free Trial' : '🎁 የ14 ቀን ነጻ ሙከራ ጀምር', 'callback_data' => 'license:trial:0'],
            ],
            [
                ['text' => $locale === 'en' ? '💳 Buy / Upgrade' : '💳 ፈቃድ ግዛ / አሻሽል', 'callback_data' => 'license:buy:0'],
                ['text' => $locale === 'en' ? '🔑 Enter Token' : '🔑 ፈቃድ አስገባ', 'callback_data' => 'license:enter:0'],
            ],
            [
                ['text' => $locale === 'en' ? '◀️ Back to Settings' : '◀️ ወደ ቅንብሮች ተመለስ', 'callback_data' => 'settings:back:0'],
            ],
        ];

        if ($messageId !== null) {
            $this->telegram->editMessageText($chatId, $messageId, $text, [
                'reply_markup' => TelegramUi::inlineKeyboard($buttons),
            ]);
        } else {
            $this->telegram->sendMessage($chatId, $text, [
                'reply_markup' => TelegramUi::inlineKeyboard($buttons),
            ]);
        }
    }
}
