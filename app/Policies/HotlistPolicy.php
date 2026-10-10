<?php

namespace App\Policies;

use App\Models\Hotlist;
use App\Models\User;
use InsightLPR\BlackAdam\Enum\PermissionEnum;

class HotlistPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionEnum::ViewAny_Hotlist->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Hotlist $hotlist): bool
    {
        return $user->can(PermissionEnum::ViewAny_Hotlist->value)
            && $user->org_id == $hotlist->org_id;
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
    public function update(User $user, Hotlist $hotlist): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Hotlist $hotlist): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Hotlist $hotlist): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Hotlist $hotlist): bool
    {
        return false;
    }
}
