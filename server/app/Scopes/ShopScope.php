<?php

declare(strict_types=1);

namespace App\Scopes;

use App\Context\CurrentShopContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ShopScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (CurrentShopContext::has()) {
            $builder->where($model->qualifyColumn('shop_id'), CurrentShopContext::getId());
        } else {
            // When no shop context is active, prevent all cross-shop data leaks
            $builder->whereNull($model->qualifyColumn('shop_id'));
        }
    }
}
