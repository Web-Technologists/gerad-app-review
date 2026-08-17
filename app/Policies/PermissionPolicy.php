<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Illuminate\Auth\Access\HandlesAuthorization;

class PermissionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('manage_users');
    }

    public function view(User $user, Permission $permission): bool
    {
        return $user->hasPermission('manage_users');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('manage_users');
    }

    public function update(User $user, Permission $permission): bool
    {
        return $user->hasPermission('manage_users');
    }

    public function delete(User $user, Permission $permission): bool
    {
        return $user->hasPermission('manage_users');
    }
}
