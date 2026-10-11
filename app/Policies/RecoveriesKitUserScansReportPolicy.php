<?php

namespace App\Policies;

use App\Models\RecoveriesKitUserScansReport;
use App\Models\User;
use InsightLPR\BlackAdam\Enum\PermissionEnum;

class RecoveriesKitUserScansReportPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, RecoveriesKitUserScansReport $report): bool
    {
        return $user->can(PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value)
            && $user->org_id == $report->org_id;
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
    public function update(User $user, RecoveriesKitUserScansReport $report): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, RecoveriesKitUserScansReport $report): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, RecoveriesKitUserScansReport $report): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, RecoveriesKitUserScansReport $report): bool
    {
        return false;
    }
}
