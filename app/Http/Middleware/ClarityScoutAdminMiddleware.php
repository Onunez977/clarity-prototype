<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\FeatureEnum;
use App\Filament\Resources\ScoutUsersResource;
use Closure;
use Illuminate\Http\Request;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the admin-facing Scout Bird's Eye surface (DEV-2116): the org admin's
 * web session must be Scout-enabled AND hold the admin role — the same gate the
 * Scout Filament resources use ({@see ScoutUsersResource}).
 * Distinct from {@see ClarityScoutMiddleware}, which guards the driver PWA's
 * Sanctum API (driver permission + assigned kit).
 */
class ClarityScoutAdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        setPermissionsTeamId($user->org_id);

        $allowed = (Feature::for($user)->active(FeatureEnum::CLARITY_SCOUT->slug()) || session()->has('admin_impersonator'))
            && $user->hasRole(RoleEnum::ADMIN->value);

        abort_unless($allowed, 403);

        return $next($request);
    }
}
