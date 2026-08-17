<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Product;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProductPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('view_products');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->hasPermission('view_products');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('edit_upi');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->hasPermission('edit_upi');
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->hasPermission('edit_upi');
    }
}
