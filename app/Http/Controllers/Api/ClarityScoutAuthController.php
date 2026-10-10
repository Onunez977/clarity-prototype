<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\FeatureEnum;
use App\Enums\ScoutAnalyticsEvent;
use App\Enums\ScoutAnalyticsPage;
use App\Facades\Analytics;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ScoutOnboardingNotification;
use App\Notifications\ScoutPasswordResetNotification;
use App\Support\Scout\ScoutDriverFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Pennant\Feature;

class ClarityScoutAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        setPermissionsTeamId($user->org_id);

        if (! $user->hasPermissionTo('clarity-scout')) {
            return response()->json([
                'message' => 'You do not have portal access',
            ], 403);
        }

        if (! $user->kit_id) {
            return response()->json([
                'message' => 'No scanner kit assigned to your account',
            ], 403);
        }

        if (! Feature::for($user)->active(FeatureEnum::CLARITY_SCOUT->slug())) {
            return response()->json([
                'message' => 'Clarity Scout is not enabled for your organization.',
            ], 403);
        }

        $kit = $user->kit;

        if (! $kit) {
            return response()->json([
                'message' => 'No scanner kit assigned to your account.',
            ], 403);
        }

        $user->tokens()->where('name', 'clarity-scout-token')->delete();

        $token = $user->createToken('clarity-scout-token')->plainTextToken;

        Analytics::track(ScoutAnalyticsEvent::Login->value, $user, ['action' => 'login'], page: ScoutAnalyticsPage::App->value);

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'org_id' => $user->org_id,
                'org_name' => $user->org?->name,
            ],
            'kit' => [
                'id' => $kit->id,
                'name' => $kit->name,
                'kit_mapping' => $kit->kit_mapping,
            ],
            'is_impersonating' => false,
            ...ScoutDriverFlags::for($user, isImpersonating: false),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::query()->where('email', $request->string('email')->toString())->first();

        if ($user?->org_id) {
            setPermissionsTeamId($user->org_id);

            $canScout = $user->hasPermissionTo('clarity-scout')
                && Feature::for($user)->active(FeatureEnum::CLARITY_SCOUT->slug());

            if ($canScout) {
                if ($user->hasVerifiedEmail()) {
                    $user->notify(new ScoutPasswordResetNotification);
                } else {
                    $user->notify(new ScoutOnboardingNotification);
                }

                Analytics::track(
                    ScoutAnalyticsEvent::ForgotPassword->value,
                    $user,
                    ['action' => 'forgot_password'],
                    page: ScoutAnalyticsPage::App->value,
                );
            }
        }

        // Always the same response — do not reveal whether the email exists.
        return response()->json([
            'message' => 'If that email is registered for Clarity Scout, we sent a reset link.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isScoutForceImpersonator()) {
            Analytics::track(ScoutAnalyticsEvent::Logout->value, $user, ['action' => 'logout'], page: ScoutAnalyticsPage::App->value);
        }

        $user->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $kit = $user->kit;

        $isImpersonating = $user->isScoutForceImpersonator();

        return response()->json([
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
            'is_impersonating' => $isImpersonating,
            ...ScoutDriverFlags::for($user, isImpersonating: $isImpersonating),
        ]);
    }
}
