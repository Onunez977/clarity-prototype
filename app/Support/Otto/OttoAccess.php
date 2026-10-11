<?php

namespace App\Support\Otto;

use App\Models\Org;
use App\Models\User;

/**
 * Otto access: Clarity Pro Lite or higher, plus the Spatie use-otto permission.
 * Staff impersonation (admin_impersonator session) is allowed for QA when the org's plan qualifies.
 */
final class OttoAccess
{
    public const PERMISSION = 'use-otto';

    public static function orgHasOttoEnabled(?Org $org): bool
    {
        if (! $org) {
            return false;
        }

        $org->loadMissing('meta');

        return $org->ottoEnabled();
    }

    public static function userCanUseOtto(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $user->loadMissing('org.meta');

        if ($user->org_id !== null) {
            setPermissionsTeamId($user->org_id);
        }

        // Staff QA while impersonating may use Otto when the org has it enabled.
        // Team id is set first so later ViewAny_* checks on this request (and a
        // sync queue job) do not resolve against a null team.
        if (session()->has('admin_impersonator') && self::orgHasOttoEnabled($user->org)) {
            return true;
        }

        return self::orgHasOttoEnabled($user->org)
            && $user->checkPermissionTo(self::PERMISSION, 'web');
    }
}
