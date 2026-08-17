<?php

namespace App\Repositories\Contracts;

use App\Models\Shop;

interface ShopRepositoryInterface
{
    /**
     * Find a Shop record by its primary domain.
     */
    public function findByDomain(string $domain): ?Shop;

    /**
     * Find a Shop record by its local database ID.
     */
    public function find(int $id): ?Shop;

    /**
     * Update or create a Shop record.
     */
    public function updateOrCreate(array $attributes, array $values): Shop;
}
