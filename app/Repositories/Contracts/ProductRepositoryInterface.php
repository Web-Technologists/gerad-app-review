<?php

namespace App\Repositories\Contracts;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

interface ProductRepositoryInterface
{
    /**
     * Find a product record by its external Shopify ID.
     */
    public function findByShopifyId(int $shopifyProductId): ?Product;

    /**
     * Find a product record by its local database primary key.
     */
    public function find(int $id): ?Product;

    /**
     * Update or create a product record.
     */
    public function updateOrCreate(array $attributes, array $values): Product;

    /**
     * Delete a product record from a store.
     */
    public function deleteByShopifyId(int $shopId, int $shopifyProductId): bool;

    /**
     * Get a query builder instance scoped and filtered by search params.
     */
    public function getFilteredProductsQuery(array $filters): Builder;
}
