<?php

declare(strict_types=1);

namespace App\Context;

use App\Models\Shop;
use Closure;

/**
 * Manages the explicit current shop context for multi-tenant scoping.
 */
class CurrentShopContext
{
    protected static ?string $shopId = null;
    protected static ?Shop $shop = null;

    /**
     * Set the current active shop context.
     */
    public static function set(Shop|string $shop): void
    {
        if ($shop instanceof Shop) {
            self::$shop = $shop;
            self::$shopId = (string) $shop->id;
        } else {
            self::$shop = null;
            self::$shopId = $shop;
        }
    }

    /**
     * Get the active shop ID, if set.
     */
    public static function getId(): ?string
    {
        return self::$shopId;
    }

    /**
     * Get the active Shop model instance.
     */
    public static function get(): ?Shop
    {
        if (self::$shop === null && self::$shopId !== null) {
            self::$shop = Shop::find(self::$shopId);
        }

        return self::$shop;
    }

    /**
     * Determine whether an active shop context exists.
     */
    public static function has(): bool
    {
        return self::$shopId !== null && self::$shopId !== '';
    }

    /**
     * Clear the current shop context.
     */
    public static function clear(): void
    {
        self::$shop = null;
        self::$shopId = null;
    }

    /**
     * Execute a callback within an isolated shop context, restoring previous context upon completion.
     */
    public static function runWith(Shop|string $shop, Closure $callback): mixed
    {
        $previousShop = self::$shop;
        $previousShopId = self::$shopId;

        try {
            self::set($shop);
            return $callback();
        } finally {
            self::$shop = $previousShop;
            self::$shopId = $previousShopId;
        }
    }
}
