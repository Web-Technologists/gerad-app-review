<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Auth\Access\HandlesAuthorization;

class RolePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('manage_users');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermission('manage_users');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('manage_users');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasPermission('manage_users');
    }

    public function delete(User $user, Role $role): bool
    {
        // Don't allow deleting the default Super Admin role to prevent lockouts
        return $user->hasPermission('manage_users') && $role->name !== 'Super Admin';
    }
}
