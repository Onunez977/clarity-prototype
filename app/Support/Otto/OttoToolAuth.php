<?php

declare(strict_types=1);

namespace App\Support\Otto;

use App\Models\User;

/**
 * Spatie team-aware permission check for Otto tools.
 *
 * Horizon reuses the worker (stale team id / loaded relations). A fresh
 * impersonation session can also resolve ViewAny_* before OrgMiddleware
 * pins the team — ScanSearchTool then disappears from the first turn.
 */
final class OttoToolAuth
{
    public static function userCan(User $user, string $permission): bool
    {
        if ($user->org_id !== null) {
            setPermissionsTeamId($user->org_id);
        }

        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');

        return $user->can($permission);
    }
}
