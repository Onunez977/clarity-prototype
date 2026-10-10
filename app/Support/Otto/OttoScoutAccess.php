<?php

declare(strict_types=1);

namespace App\Support\Otto;

use App\Enums\FeatureEnum;
use App\Jobs\RunsOttoStream;
use App\Models\User;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;

/**
 * Shared Scout + org-admin gate for Otto tools.
 *
 * Horizon restores `admin_impersonator` on the job before tools run
 * ({@see RunsOttoStream}); that stamp is the Scout-flag bypass only.
 */
final class OttoScoutAccess
{
    public const ZONE_NEEDS_SCOUT = 'Zone search and creation need Clarity Scout. If your organization does not have Scout yet, ask your Clarity account manager about enabling it.';

    public const ZONE_NEEDS_ADMIN = 'Zone search and creation are limited to organization admins.';

    public static function scoutIsAvailable(User $user): bool
    {
        self::restorePermissionsTeam($user);

        return Feature::for($user)->active(FeatureEnum::CLARITY_SCOUT->slug())
            || session()->has('admin_impersonator');
    }

    public static function userIsOrgAdmin(User $user): bool
    {
        self::restorePermissionsTeam($user);

        return $user->hasRole(RoleEnum::ADMIN->value);
    }

    public static function adminCanUseScout(User $user): bool
    {
        return self::scoutIsAvailable($user) && self::userIsOrgAdmin($user);
    }

    /**
     * Spatie roles and {@see User::isProEnabled()} (which calls hasRole) are
     * team-scoped. Horizon usually restores the team before tools run, but
     * tools can also be invoked directly — set it here before any Feature or
     * role check so a missing team id cannot deny a real org admin.
     */
    private static function restorePermissionsTeam(User $user): void
    {
        if ($user->org_id === null) {
            return;
        }

        setPermissionsTeamId($user->org_id);
    }

    public static function zoneDeniedMessage(User $user): ?string
    {
        if (! self::scoutIsAvailable($user)) {
            return self::ZONE_NEEDS_SCOUT;
        }

        if (! self::userIsOrgAdmin($user)) {
            return self::ZONE_NEEDS_ADMIN;
        }

        return null;
    }
}
