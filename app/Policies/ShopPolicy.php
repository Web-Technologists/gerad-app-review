<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Shop;
use Illuminate\Auth\Access\HandlesAuthorization;

class ShopPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('manage_stores') || $user->hasPermission('view_products');
    }

    public function view(User $user, Shop $shop): bool
    {
        return $user->hasPermission('manage_stores') || $user->hasPermission('view_products');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('manage_stores');
    }

    public function update(User $user, Shop $shop): bool
    {
        return $user->hasPermission('manage_stores');
    }

    public function delete(User $user, Shop $shop): bool
    {
        return $user->hasPermission('manage_stores');
    }
}
