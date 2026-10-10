<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\FeatureEnum;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

class ClarityScoutMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Check the literal ability — `can()` is true for any `*` token, which is
        // the default for normal scout tokens, so it can't distinguish impersonation.
        if (in_array('clarity-scout-force', $user->currentAccessToken()?->abilities ?? [], true)) {
            return $next($request);
        }

        setPermissionsTeamId($user->org_id);

        // Access-loss cases return 401 (not 403) so the driver PWA's existing
        // 401 handler logs out and routes back to login instead of stranding the
        // driver on a dead, still-"authenticated" screen (F4). In-app authorization
        // 403s (e.g. an unassigned zone) live in the controllers and stay 403.
        if (! $user->hasPermissionTo('clarity-scout')) {
            return response()->json(['message' => 'You do not have portal access'], 401);
        }

        if (! $user->kit_id) {
            return response()->json(['message' => 'No scanner kit assigned to your account'], 401);
        }

        if (! Feature::for($user)->active(FeatureEnum::CLARITY_SCOUT->slug())) {
            return response()->json(['message' => 'Clarity Scout is not enabled'], 401);
        }

        return $next($request);
    }
}
