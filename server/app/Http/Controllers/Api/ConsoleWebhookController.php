<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ShopLicense;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\CrockfordBase32;
use App\Services\DateEpochService;
use App\Services\HmacService;
use App\Services\LicenseVerifier;
use App\Services\Telegram\TelegramClient;
use App\Support\EthiopicCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ConsoleWebhookController extends Controller
{
    public function __construct(
        protected TelegramClient $telegram
    ) {}

    /**
     * Handle incoming license webhooks from the License Console.
     * POST /api/console/license-events
     */
    public function handle(Request $request): JsonResponse
    {
        // 1. Verify HMAC signature and replay protection
        $authCheck = HmacService::verifyRequest($request);
        if (!$authCheck['valid']) {
            return response()->json([
                'error' => $authCheck['error'] ?? 'Unauthorized',
            ], 401);
        }

        $event = $request->input('event');
        $payload = $request->input('payload') ?? $request->all();

        Log::info("Received license webhook event: {$event}", ['payload' => $payload]);

        $token = $payload['token'] ?? null;
        $bindCode = $payload['bind_code'] ?? $payload['code'] ?? null;
        $targetTelegramId = $payload['telegram_id'] ?? $payload['customer_telegram_id'] ?? null;

        // 2. Locate the shop if shop-bound
        $shop = null;
        if (!empty($payload['shop_id'])) {
            $shop = Shop::find($payload['shop_id']);
        }

        $tokenFields = null;
        if ($token) {
            try {
                $clean = CrockfordBase32::normalize($token);
                if (str_starts_with($clean, 'LIC1')) {
                    $b32 = strtr(substr($clean, 4), ['O' => '0', 'I' => '1', 'L' => '1']);
                    $decoded = CrockfordBase32::decode($b32);
                    $tokenFields = LicenseVerifier::parsePayload(substr($decoded, 0, 31));
                }
            } catch (\Throwable $e) {
                // Invalid token structure
            }
        }

        if ($shop === null && $tokenFields && $tokenFields['type'] === 2) {
            // Find shop matching bind
            $targetBind = $tokenFields['bind_bytes'];
            foreach (Shop::all() as $s) {
                if (hash_equals($s->getBindBytes(), $targetBind)) {
                    $shop = $s;
                    break;
                }
            }
        }

        if ($shop === null && $bindCode && str_starts_with(strtoupper($bindCode), 'S')) {
            try {
                $parsed = CrockfordBase32::parseCode($bindCode);
                foreach (Shop::all() as $s) {
                    if (hash_equals($s->getBindBytes(), $parsed['bind_bytes'])) {
                        $shop = $s;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                // Invalid code
            }
        }

        // 3. Process event
        switch ($event) {
            case 'token_issued':
            case 'renewed':
                if ($shop !== null && $token) {
                    $verification = LicenseVerifier::verify(
                        tokenString: $token,
                        expectedType: 2,
                        expectedBind: $shop->getBindBytes()
                    );
                    $p = $verification['payload'] ?? $tokenFields;

                    ShopLicense::updateOrCreate(
                        ['shop_id' => $shop->id],
                        [
                            'token' => $token,
                            'license_id' => $p['license_id_hex'] ?? null,
                            'plan' => $p['plan'] ?? null,
                            'exp_day' => $p['exp_day'] ?? null,
                            'grace_days' => $p['grace_days'] ?? 7,
                            'seats' => $p['seats'] ?? 1,
                            'features' => $p['features'] ?? 0,
                            'status' => 'active',
                        ]
                    );

                    // Notify shop owner on Telegram
                    $this->notifyShopOwnerOfActivation($shop, $token, $p);
                } elseif ($targetTelegramId && $token) {
                    // Offline customer device token delivery
                    $this->notifyOfflineCustomerOfToken((int) $targetTelegramId, $token, $bindCode, $tokenFields);
                }
                break;

            case 'suspended':
                if ($shop !== null) {
                    ShopLicense::where('shop_id', $shop->id)->update(['status' => 'suspended']);
                    $this->notifyShopOwnerStatusChange($shop, 'suspended');
                }
                break;

            case 'revoked':
                if ($shop !== null) {
                    ShopLicense::where('shop_id', $shop->id)->update(['status' => 'revoked']);
                    $this->notifyShopOwnerStatusChange($shop, 'revoked');
                }
                break;

            default:
                Log::info("Unhandled license webhook event: {$event}");
                break;
        }

        return response()->json([
            'success' => true,
            'event' => $event,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Notify shop owner that their license has been activated/renewed.
     */
    protected function notifyShopOwnerOfActivation(Shop $shop, string $token, ?array $fields): void
    {
        $owner = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->where('role', 'owner')
            ->first();

        if ($owner === null || empty($owner->telegram_id)) {
            return;
        }

        $locale = $owner->language ?: 'am';
        $expDay = $fields['exp_day'] ?? null;
        $seats = $fields['seats'] ?? 1;

        $expiryStr = 'Lifetime (የዕድሜ ልክ)';
        if ($expDay !== null && $expDay !== 0xFFFF) {
            $expDate = DateEpochService::dayToDate($expDay);
            $gregorian = $expDate->format('Y-m-d');
            $ethiopian = EthiopicCalendar::format($expDate, $locale);
            $expiryStr = "{$gregorian} ({$ethiopian})";
        }

        if ($locale === 'en') {
            $text = "🎉 <b>Shop License Activated!</b>\n\n"
                  . "Your license for <b>{$shop->name}</b> is now active.\n\n"
                  . "📅 <b>Expiry:</b> {$expiryStr}\n"
                  . "📱 <b>Seats Allowed:</b> {$seats} devices\n\n"
                  . "<b>Token:</b>\n<code>{$token}</code>\n\n"
                  . "<i>Tap the token above to copy. The server has automatically applied it.</i>";
        } else {
            $text = "🎉 <b>የሱቅ ፈቃድዎ ነቅቷል!</b>\n\n"
                  . "የሱቅዎ (<b>{$shop->name}</b>) የፈቃድ ምዝገባ በተሳካ ሁኔታ ተጠናቋል።\n\n"
                  . "📅 <b>የሚያበቃበት ቀን:</b> {$expiryStr}\n"
                  . "📱 <b>የተፈቀዱ ስልኮች:</b> {$seats}\n\n"
                  . "<b>የፈቃድ ቁጥር (Token):</b>\n<code>{$token}</code>\n\n"
                  . "<i>ቶከኑን በመንካት ኮፒ ማድረግ ይችላሉ። ሰርቨሩ ላይ በራስ-ሰር ተስተካክሏል።</i>";
        }

        try {
            $this->telegram->sendMessage((int)$owner->telegram_id, $text);
        } catch (\Throwable $e) {
            Log::warning("Failed to notify shop owner on Telegram: " . $e->getMessage());
        }
    }

    /**
     * Notify offline customer who purchased a device-bound token via Telegram.
     */
    protected function notifyOfflineCustomerOfToken(int $telegramId, string $token, ?string $code, ?array $fields): void
    {
        $codeStr = $code ? "<code>{$code}</code>" : 'Your device';
        $expDay = $fields['exp_day'] ?? null;

        $expiryStr = 'Lifetime';
        if ($expDay !== null && $expDay !== 0xFFFF) {
            $expDate = DateEpochService::dayToDate($expDay);
            $expiryStr = $expDate->format('Y-m-d');
        }

        $text = "🎉 <b>Device License Token Ready!</b>\n\n"
              . "License for device {$codeStr} has been approved and issued.\n\n"
              . "📅 <b>Valid until:</b> {$expiryStr}\n\n"
              . "<b>Token (Tap to copy):</b>\n"
              . "<code>{$token}</code>\n\n"
              . "<b>Instructions:</b>\n"
              . "1. Open the TGStock POS app on your device\n"
              . "2. Go to ⚙️ <b>Settings</b> → 🔑 <b>License</b>\n"
              . "3. Tap <b>Enter Token</b> and paste this token";

        try {
            $this->telegram->sendMessage($telegramId, $text);
        } catch (\Throwable $e) {
            Log::warning("Failed to deliver offline token to Telegram user: " . $e->getMessage());
        }
    }

    /**
     * Notify shop owner of administrative suspension or revocation.
     */
    protected function notifyShopOwnerStatusChange(Shop $shop, string $status): void
    {
        $owner = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->where('role', 'owner')
            ->first();

        if ($owner === null || empty($owner->telegram_id)) {
            return;
        }

        $locale = $owner->language ?: 'am';
        if ($status === 'suspended') {
            $text = $locale === 'en'
                ? "⚠️ <b>Shop License Suspended</b>\n\nYour license for {$shop->name} has been suspended. Please contact product support."
                : "⚠️ <b>የሱቅ ፈቃድ ታግዷል</b>\n\nየሱቅዎ ({$shop->name}) ፈቃድ ለጊዜው ታግዷል። እባክዎ አስተዳዳሪን ያነጋግሩ።";
        } else {
            $text = $locale === 'en'
                ? "❌ <b>Shop License Revoked</b>\n\nYour license for {$shop->name} has been administratively revoked."
                : "❌ <b>የሱቅ ፈቃድ ተሰርዟል</b>\n\nየሱቅዎ ({$shop->name}) ፈቃድ ተሰርዟል።";
        }

        try {
            $this->telegram->sendMessage((int)$owner->telegram_id, $text);
        } catch (\Throwable $e) {
            Log::warning("Failed to notify shop owner of status change: " . $e->getMessage());
        }
    }
}
