<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\PairingCode;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Support\ImmediateTransaction;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PairingService
{
    /**
     * Start a new device pairing session.
     * Generates a 6-digit single-use code expiring in 10 minutes, stored as SHA-256 hash.
     *
     * @return array{code: string, deep_link: string}
     */
    public function startPairing(?string $shopName, string $deviceName): array
    {
        $code = (string) random_int(100000, 999999);
        $codeHash = hash('sha256', $code);

        PairingCode::create([
            'id' => (string) Str::uuid7(),
            'code_hash' => $codeHash,
            'shop_name' => $shopName ? trim($shopName) : null,
            'device_name' => trim($deviceName),
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ]);

        $botUsername = config('services.telegram.bot_username', 'InventoryEtBot');
        $deepLink = "https://t.me/{$botUsername}?start=pair_{$code}";

        return [
            'code' => $code,
            'deep_link' => $deepLink,
        ];
    }

    /**
     * Check status of a pairing code.
     * If confirmed, returns device_token exactly once, then wipes it from the pairing record.
     *
     * @return array{status: 'pending'|'confirmed'|'expired', device_token?: string}
     */
    public function getPairingStatus(string $code): array
    {
        $codeHash = hash('sha256', trim($code));

        $pairing = PairingCode::where('code_hash', $codeHash)->first();

        if ($pairing === null || $pairing->isExpired()) {
            if ($pairing !== null && $pairing->status !== 'expired') {
                $pairing->status = 'expired';
                $pairing->device_token = null;
                $pairing->save();
            }

            return ['status' => 'expired'];
        }

        if ($pairing->status === 'pending') {
            return ['status' => 'pending'];
        }

        if ($pairing->status === 'confirmed') {
            if (!empty($pairing->device_token)) {
                $plainToken = $pairing->device_token;
                // Return exactly once: clear immediately from database
                $pairing->device_token = null;
                $pairing->save();

                return [
                    'status' => 'confirmed',
                    'device_token' => $plainToken,
                ];
            }

            return ['status' => 'confirmed'];
        }

        return ['status' => 'expired'];
    }

    /**
     * Confirm a pairing code from the Telegram bot.
     * Attaches or creates user, creates shop if user has none, and generates revocable device token.
     *
     * @return array{shop: Shop, user: User, device: Device}
     */
    public function confirmPairing(string $code, int|string $telegramId, string $telegramName, ?string $language = 'am'): array
    {
        $codeHash = hash('sha256', trim($code));

        $pairing = PairingCode::where('code_hash', $codeHash)->first();

        if ($pairing === null || $pairing->isExpired()) {
            throw new InvalidArgumentException('Pairing code is invalid or has expired.');
        }

        if ($pairing->status === 'confirmed' && $pairing->device_id !== null) {
            $device = Device::withoutGlobalScope(ShopScope::class)->find($pairing->device_id);
            $shop = Shop::find($pairing->shop_id);
            $user = User::withoutGlobalScope(ShopScope::class)->find($pairing->user_id);

            if ($device && $shop && $user) {
                return ['shop' => $shop, 'user' => $user, 'device' => $device];
            }
        }

        return ImmediateTransaction::run(function () use ($pairing, $telegramId, $telegramName, $language): array {
            // Find existing user by telegram_id
            $user = User::withoutGlobalScope(ShopScope::class)
                ->where('telegram_id', $telegramId)
                ->first();

            if ($user === null) {
                // New user: create shop
                $shopName = $pairing->shop_name ?: ($telegramName . ' Shop');
                $shop = Shop::create([
                    'id' => (string) Str::uuid7(),
                    'name' => $shopName,
                    'currency' => 'ETB',
                    'language' => $language ?: 'am',
                    'low_stock_default_milli' => 5000,
                ]);
                $shop->getDefaultLocation();

                return \App\Context\CurrentShopContext::runWith($shop, function () use ($shop, $pairing, $telegramId, $telegramName, $language): array {
                    $user = User::withoutGlobalScope(ShopScope::class)->create([
                        'id' => (string) Str::uuid7(),
                        'telegram_id' => $telegramId,
                        'name' => $telegramName,
                        'language' => $language ?: 'am',
                        'shop_id' => $shop->id,
                        'role' => 'owner',
                        'is_active' => true,
                    ]);

                    // Generate cryptographically secure device bearer token (64 hex characters)
                    $plainToken = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $plainToken);

                    $device = Device::withoutGlobalScope(ShopScope::class)->create([
                        'id' => (string) Str::uuid7(),
                        'shop_id' => $shop->id,
                        'user_id' => $user->id,
                        'name' => $pairing->device_name,
                        'token_hash' => $tokenHash,
                        'last_seen_at' => now(),
                    ]);

                    // Mark pairing code confirmed and stage plain token for one-time retrieval
                    $pairing->status = 'confirmed';
                    $pairing->device_token = $plainToken;
                    $pairing->device_id = $device->id;
                    $pairing->shop_id = $shop->id;
                    $pairing->user_id = $user->id;
                    $pairing->save();

                    return [
                        'shop' => $shop,
                        'user' => $user,
                        'device' => $device,
                    ];
                });
            }

            $shop = Shop::find($user->shop_id);
            if ($shop === null) {
                $shop = Shop::create([
                    'id' => (string) Str::uuid7(),
                    'name' => $pairing->shop_name ?: ($telegramName . ' Shop'),
                    'currency' => 'ETB',
                    'language' => $language ?: 'am',
                    'low_stock_default_milli' => 5000,
                ]);
                $user->shop_id = $shop->id;
                $user->save();
            }
            $shop->getDefaultLocation();

            return \App\Context\CurrentShopContext::runWith($shop, function () use ($shop, $user, $pairing): array {
                // Generate cryptographically secure device bearer token (64 hex characters)
                $plainToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $plainToken);

                $device = Device::withoutGlobalScope(ShopScope::class)->create([
                    'id' => (string) Str::uuid7(),
                    'shop_id' => $shop->id,
                    'user_id' => $user->id,
                    'name' => $pairing->device_name,
                    'token_hash' => $tokenHash,
                    'last_seen_at' => now(),
                ]);

                // Mark pairing code confirmed and stage plain token for one-time retrieval
                $pairing->status = 'confirmed';
                $pairing->device_token = $plainToken;
                $pairing->device_id = $device->id;
                $pairing->shop_id = $shop->id;
                $pairing->user_id = $user->id;
                $pairing->save();

                return [
                    'shop' => $shop,
                    'user' => $user,
                    'device' => $device,
                ];
            });
        });
    }
}
