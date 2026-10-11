<?php

namespace App\Policies;

use App\Models\User;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Spatie\Activitylog\Models\Activity;

class ActivityPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleEnum::ADMIN->value) || ($user->hasPermissionTo(PermissionEnum::VIEW_ACCOUNT_ACTIVITY->value) && ! $user->hasRole(RoleEnum::CLIENT->value));
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Activity $activity): bool
    {
        return $user->hasRole(RoleEnum::ADMIN->value) || ($user->hasPermissionTo(PermissionEnum::VIEW_ACCOUNT_ACTIVITY->value) && ! $user->hasRole(RoleEnum::CLIENT->value));
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Activity $activity): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Activity $activity): bool
    {
        return false;
    }
}
