<?php

namespace App\Policies;

use App\Enums\FeatureEnum;
use App\Models\MissedOpportunity;
use App\Models\User;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Laravel\Pennant\Feature;

class MissedOpportunityPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionEnum::ViewAny_Hit->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, MissedOpportunity $hit): bool
    {
        return (session()->has('admin_impersonator') || Feature::for($user)->active(FeatureEnum::MISSED_OPPORTUNITY->slug()))
            && $user->can(PermissionEnum::ViewAny_Hit->value)
            && $user->org_id == $hit->kit?->org_id;
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
    public function update(User $user, MissedOpportunity $hit): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, MissedOpportunity $hit): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, MissedOpportunity $hit): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, MissedOpportunity $hit): bool
    {
        return false;
    }
}
