<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Change;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Scopes\ShopScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PaymentProviderService
{
    /**
     * List providers for a shop and kind ('bank' or 'wallet'), sorted with favorites and recents first.
     *
     * @return Collection<int, array{provider_key: string, kind: string, name_en: string, name_am: ?string, display_name: string, favorite: bool, enabled: bool, is_custom: bool}>
     */
    public function listProviders(string $shopId, string $kind, string $locale = 'en'): Collection
    {
        $catalogConfig = $kind === 'bank'
            ? config('payment_catalog.banks', [])
            : config('payment_catalog.wallets', []);

        // Load custom and overridden providers for this shop
        $overrides = PaymentProvider::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('kind', $kind)
            ->get()
            ->keyBy('provider_key');

        // Recent provider keys used in this shop
        $recentKeys = Payment::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('method', $kind)
            ->whereNotNull('provider_key')
            ->orderByDesc('received_at_ms')
            ->limit(20)
            ->pluck('provider_key')
            ->unique()
            ->values()
            ->all();

        $recentRank = array_flip($recentKeys);

        $result = collect();

        // 1. Process catalog providers
        foreach ($catalogConfig as $key => $cat) {
            $override = $overrides->get($key);
            $enabled = $override ? (bool) $override->enabled : true;
            $favorite = $override ? (bool) $override->favorite : false;

            $nameEn = (string) ($cat['name_en'] ?? $key);
            $nameAm = !empty($cat['name_am']) ? (string) $cat['name_am'] : null;
            $displayName = ($locale === 'am' && $nameAm !== null) ? $nameAm : $nameEn;

            $result->push([
                'provider_key' => $key,
                'kind' => $kind,
                'name_en' => $nameEn,
                'name_am' => $nameAm,
                'display_name' => $displayName,
                'favorite' => $favorite,
                'enabled' => $enabled,
                'is_custom' => false,
                'recent_rank' => $recentRank[$key] ?? 999,
            ]);
        }

        // 2. Process custom providers
        foreach ($overrides as $key => $override) {
            if (str_starts_with($key, 'custom.') || !isset($catalogConfig[$key])) {
                $customName = $override->name ?? $key;
                $result->push([
                    'provider_key' => $key,
                    'kind' => $kind,
                    'name_en' => $customName,
                    'name_am' => null,
                    'display_name' => $customName,
                    'favorite' => (bool) $override->favorite,
                    'enabled' => (bool) $override->enabled,
                    'is_custom' => true,
                    'recent_rank' => $recentRank[$key] ?? 999,
                ]);
            }
        }

        // Sort: enabled first, then favorites first, then recents first, then alphabetical
        return $result->sort(function ($a, $b) {
            if ($a['favorite'] !== $b['favorite']) {
                return $b['favorite'] ? 1 : -1;
            }
            if ($a['recent_rank'] !== $b['recent_rank']) {
                return $a['recent_rank'] <=> $b['recent_rank'];
            }
            return strcasecmp($a['display_name'], $b['display_name']);
        })->values();
    }

    /**
     * Search providers by query.
     */
    public function searchProviders(string $shopId, string $kind, string $query, string $locale = 'en'): Collection
    {
        $all = $this->listProviders($shopId, $kind, $locale);
        $clean = mb_strtolower(trim($query));

        if ($clean === '') {
            return $all;
        }

        return $all->filter(function ($p) use ($clean) {
            return str_contains(mb_strtolower($p['name_en']), $clean)
                || ($p['name_am'] && str_contains(mb_strtolower($p['name_am']), $clean))
                || str_contains(mb_strtolower($p['provider_key']), $clean);
        })->values();
    }

    /**
     * Create a custom provider (e.g. from "Other bank..." or "Other wallet...").
     */
    public function createCustomProvider(string $shopId, string $kind, string $name, ?string $deviceId = null): PaymentProvider
    {
        $uuid = (string) Str::uuid7();
        $providerKey = "custom.{$uuid}";
        $nowMs = (int) (microtime(true) * 1000);

        $provider = PaymentProvider::withoutGlobalScope(ShopScope::class)->create([
            'shop_id' => $shopId,
            'provider_key' => $providerKey,
            'kind' => $kind,
            'name' => trim($name),
            'enabled' => true,
            'favorite' => false,
            'updated_at_ms' => $nowMs,
        ]);

        Change::withoutGlobalScope(ShopScope::class)->create([
            'shop_id' => $shopId,
            'device_id' => $deviceId,
            'entity' => 'payment_provider',
            'entity_id' => $providerKey,
        ]);

        return $provider;
    }

    /**
     * Toggle favorite status on a provider.
     */
    public function toggleFavorite(string $shopId, string $providerKey, string $kind, ?string $deviceId = null): bool
    {
        $nowMs = (int) (microtime(true) * 1000);
        $provider = PaymentProvider::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('provider_key', $providerKey)
            ->first();

        if ($provider !== null) {
            $provider->favorite = !$provider->favorite;
            $provider->updated_at_ms = $nowMs;
            $provider->save();
            $newFav = $provider->favorite;
        } else {
            $provider = PaymentProvider::withoutGlobalScope(ShopScope::class)->create([
                'shop_id' => $shopId,
                'provider_key' => $providerKey,
                'kind' => $kind,
                'name' => null,
                'enabled' => true,
                'favorite' => true,
                'updated_at_ms' => $nowMs,
            ]);
            $newFav = true;
        }

        Change::withoutGlobalScope(ShopScope::class)->create([
            'shop_id' => $shopId,
            'device_id' => $deviceId,
            'entity' => 'payment_provider',
            'entity_id' => $providerKey,
        ]);

        return $newFav;
    }

    /**
     * Toggle enabled status on a provider.
     */
    public function toggleEnabled(string $shopId, string $providerKey, string $kind, ?string $deviceId = null): bool
    {
        $nowMs = (int) (microtime(true) * 1000);
        $provider = PaymentProvider::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('provider_key', $providerKey)
            ->first();

        if ($provider !== null) {
            $provider->enabled = !$provider->enabled;
            $provider->updated_at_ms = $nowMs;
            $provider->save();
            $newEnabled = $provider->enabled;
        } else {
            $provider = PaymentProvider::withoutGlobalScope(ShopScope::class)->create([
                'shop_id' => $shopId,
                'provider_key' => $providerKey,
                'kind' => $kind,
                'name' => null,
                'enabled' => false,
                'favorite' => false,
                'updated_at_ms' => $nowMs,
            ]);
            $newEnabled = false;
        }

        Change::withoutGlobalScope(ShopScope::class)->create([
            'shop_id' => $shopId,
            'device_id' => $deviceId,
            'entity' => 'payment_provider',
            'entity_id' => $providerKey,
        ]);

        return $newEnabled;
    }
}
