<?php

namespace App\Repositories\Eloquent;

use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;

class ProductRepository implements ProductRepositoryInterface
{
    /**
     * @inheritDoc
     */
    public function findByShopifyId(int $shopifyProductId): ?Product
    {
        return Product::where('shopify_product_id', $shopifyProductId)->first();
    }

    /**
     * @inheritDoc
     */
    public function find(int $id): ?Product
    {
        return Product::find($id);
    }

    /**
     * @inheritDoc
     */
    public function updateOrCreate(array $attributes, array $values): Product
    {
        return Product::updateOrCreate($attributes, $values);
    }

    /**
     * @inheritDoc
     */
    public function deleteByShopifyId(int $shopId, int $shopifyProductId): bool
    {
        return (bool) Product::where('shop_id', $shopId)
            ->where('shopify_product_id', $shopifyProductId)
            ->delete();
    }

    /**
     * @inheritDoc
     */
    public function getFilteredProductsQuery(array $filters): Builder
    {
        $query = Product::with('shop');

        if (!empty($filters['shop_id'])) {
            $query->where('shop_id', $filters['shop_id']);
        }
        if (!empty($filters['vendor'])) {
            $query->where('vendor', $filters['vendor']);
        }
        if (!empty($filters['product_type'])) {
            $query->where('product_type', $filters['product_type']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('upi_code', 'like', "%{$search}%")
                  ->orWhere('item_category', 'like', "%{$search}%")
                  ->orWhere('shopify_product_id', 'like', "%{$search}%");
            });
        }

        return $query;
    }
}
