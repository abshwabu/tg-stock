<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Context\CurrentShopContext;
use App\Exceptions\CrossShopViolationException;
use App\Exceptions\MissingShopContextException;
use App\Models\Shop;
use App\Scopes\ShopScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Enforces single-database multi-tenancy on Eloquent models.
 * Automatically scopes queries to the active CurrentShopContext
 * and prohibits creating models without an assigned shop.
 */
trait BelongsToShop
{
    public static function bootBelongsToShop(): void
    {
        static::addGlobalScope(new ShopScope);

        static::creating(function ($model): void {
            if (empty($model->shop_id)) {
                if (!CurrentShopContext::has()) {
                    throw new MissingShopContextException(
                        sprintf('Cannot create model [%s] without a shop_id or an active CurrentShopContext.', static::class)
                    );
                }
                $model->shop_id = CurrentShopContext::getId();
            } else {
                if (CurrentShopContext::has() && (string) $model->shop_id !== (string) CurrentShopContext::getId()) {
                    throw new CrossShopViolationException(
                        sprintf(
                            'Model shop_id [%s] does not match active CurrentShopContext [%s].',
                            $model->shop_id,
                            CurrentShopContext::getId()
                        )
                    );
                }
            }
        });
    }

    /**
     * Get the owning shop for this model.
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }
}
