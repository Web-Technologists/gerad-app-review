<?php

namespace App\Repositories\Eloquent;

use App\Models\Shop;
use App\Repositories\Contracts\ShopRepositoryInterface;

class ShopRepository implements ShopRepositoryInterface
{
    /**
     * @inheritDoc
     */
    public function findByDomain(string $domain): ?Shop
    {
        return Shop::where('shop_domain', $domain)->first();
    }

    /**
     * @inheritDoc
     */
    public function find(int $id): ?Shop
    {
        return Shop::find($id);
    }

    /**
     * @inheritDoc
     */
    public function updateOrCreate(array $attributes, array $values): Shop
    {
        return Shop::updateOrCreate($attributes, $values);
    }
}
