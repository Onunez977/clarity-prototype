<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Scout\ScoutDriverFlags;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class ScoutSetPasswordController extends Controller
{
    public function show(Request $request, User $user): View
    {
        $this->guardUser($user);

        return view('auth.scout.set-password', [
            'user' => $user,
            'signedUrl' => $request->fullUrl(),
        ]);
    }

    public function update(Request $request, User $user): View
    {
        $this->guardUser($user);

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->forceFill([
            'password' => $validated['password'],
        ])->save();

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

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

    private function guardUser(User $user): void
    {
        // One-shot link: once the user has set a password and verified
        // their email, the signed URL must not let them (or anyone who
        // later sees the email) reset the password again.
        if ($user->hasVerifiedEmail()) {
            $this->rejectWith('used');
        }

        if (! $user->org_id) {
            $this->rejectWith('ineligible');
        }

        setPermissionsTeamId($user->org_id);

        if (! $user->hasPermissionTo('clarity-scout')) {
            $this->rejectWith('ineligible');
        }
    }

    private function rejectWith(string $reason): never
    {
        throw new HttpResponseException(
            response()->view('auth.scout.invalid-link', [
                'expired' => false,
                'reason' => $reason,
            ], 403)
        );
    }
}
