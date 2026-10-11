<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use InsightLPR\BlackAdam\Enum\PermissionEnum;

class RolePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionEnum::MANAGE_ACCOUNT_ROLES->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Role $model): bool
    {
        if ($model->org_id) {
            return $user->can(PermissionEnum::MANAGE_ACCOUNT_ROLES->value) && $user->org_id === $model->org_id;
        }

        return $user->can(PermissionEnum::MANAGE_ACCOUNT_ROLES->value);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can(PermissionEnum::MANAGE_ACCOUNT_ROLES->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Role $model): bool
    {
        if ($model->org_id) {
            return $user->can(PermissionEnum::MANAGE_ACCOUNT_ROLES->value) && $user->org_id === $model->org_id;
        }

        return $user->can(PermissionEnum::MANAGE_ACCOUNT_ROLES->value);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Role $model): bool
    {
        if ($model->org_id) {
            return $user->can(PermissionEnum::MANAGE_ACCOUNT_ROLES->value) && $user->org_id === $model->org_id;
        }

        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Role $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Role $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can remove another user from the role.
     */
    public function removeUser(User $user, Role $model, User $roleUser): bool
    {
        if ($model->org_id) {
            return $user->can(PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
                && $user->id !== $roleUser->id
                && $user->org_id === $model->org_id
                && $user->org_id === $roleUser->org_id
                && $roleUser->org_id === $model->org_id;
        }

        return $user->can(PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
            && $user->id !== $roleUser->id
            && $user->org_id === $roleUser->org_id;
    }
}
