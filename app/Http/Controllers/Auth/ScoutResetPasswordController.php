<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Scout\ScoutDriverFlags;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rules\Password;

class ScoutResetPasswordController extends Controller
{
    public function show(Request $request, User $user): View
    {
        $this->guardUser($request, $user);

        return view('auth.scout.reset-password', [
            'user' => $user,
            'signedUrl' => $request->fullUrl(),
        ]);
    }

    public function update(Request $request, User $user): View
    {
        $this->guardUser($request, $user);

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->forceFill([
            'password' => $validated['password'],
        ])->save();

        Cache::put($this->consumedCacheKey($request, $user), true, now()->addDays(8));

        $user->tokens()
            ->whereIn('name', ['clarity-scout-token', 'clarity-scout-force-token'])
            ->delete();

        $kit = $user->kit;
        $token = $user->createToken('clarity-scout-token')->plainTextToken;

        return view('clarity-scout', [
            'forceAuth' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'org_id' => $user->org_id,
                    'org_name' => $user->org?->name,
                ],
                'kit' => $kit ? [
                    'id' => $kit->id,
                    'name' => $kit->name,
                    'kit_mapping' => $kit->kit_mapping,
                ] : null,
                'is_impersonating' => false,
                ...ScoutDriverFlags::for($user, isImpersonating: false),
            ],
        ]);
    }

    private function guardUser(Request $request, User $user): void
    {
        if (Cache::has($this->consumedCacheKey($request, $user))) {
            $this->rejectWith('used');
        }

        // Reset links are for verified Scout users only. Unverified users
        // still use the onboarding set-password flow.
        if (! $user->hasVerifiedEmail()) {
            $this->rejectWith('ineligible');
        }

        if (! $user->org_id) {
            $this->rejectWith('ineligible');
        }

        setPermissionsTeamId($user->org_id);

        if (! $user->hasPermissionTo('clarity-scout')) {
            $this->rejectWith('ineligible');
        }
    }

    private function consumedCacheKey(Request $request, User $user): string
    {
        $signature = (string) $request->query('signature', '');

        return 'scout_password_reset_used:'.hash('sha256', $user->getKey().'|'.$signature);
    }

    private function rejectWith(string $reason): never
    {
        throw new HttpResponseException(
            response()->view('auth.scout.invalid-link', [
                'expired' => false,
                'reason' => $reason,
                'flow' => 'reset',
            ], 403)
        );
    }
}
