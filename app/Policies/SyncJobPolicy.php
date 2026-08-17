<?php

namespace App\Policies;

use App\Models\User;
use App\Models\SyncJob;
use Illuminate\Auth\Access\HandlesAuthorization;

class SyncJobPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('import_export');
    }

    public function view(User $user, SyncJob $job): bool
    {
        return $user->hasPermission('import_export');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('import_export');
    }

    public function update(User $user, SyncJob $job): bool
    {
        return $user->hasPermission('import_export');
    }

    public function delete(User $user, SyncJob $job): bool
    {
        return $user->hasPermission('import_export');
    }
}
