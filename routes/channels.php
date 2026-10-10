<?php

declare(strict_types=1);

use App\Enums\FeatureEnum;
use App\Models\AgentConversation;
use App\Models\User;
use App\Support\Otto\OttoAccess;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Pennant\Feature;

// $userId is intentionally untyped: Laravel passes the captured channel
// segment as a string, and PHP's int coercion would convert non-numeric
// input ("abc") to 0 — which we want to reject explicitly below rather
// than relying on a TypeError.
//
// Pass `guards: ['sanctum']` so Broadcaster::retrieveUser uses the Sanctum
// guard rather than $request->user() (default 'web' guard, which never
// resolves because the Scout PWA authenticates with a bearer token, not
// a session).
Broadcast::channel('clarity.scout.user.{userId}', function (User $user, $userId) {
    $userId = (int) $userId;

    if ($userId <= 0 || (int) $user->id !== $userId) {
        return false;
    }

    // Admin force-login (impersonation) tokens carry `clarity-scout-force`
    // and bypass the kit/permission/feature gate, mirroring ClarityScoutMiddleware.
    // Check the literal ability — `can()` is true for any `*` token, which is the
    // default for normal scout tokens, so it can't distinguish impersonation.
    if (in_array('clarity-scout-force', $user->currentAccessToken()?->abilities ?? [], true)) {
        return true;
    }

    if (! $user->kit_id) {
        return false;
    }

    setPermissionsTeamId($user->org_id);

    if (! $user->hasPermissionTo('clarity-scout')) {
        return false;
    }

    return Feature::for($user)->active(FeatureEnum::CLARITY_SCOUT->slug());
}, ['guards' => ['sanctum']]);

Broadcast::channel('otto-thread.{conversationId}', function (User $user, string $conversationId) {
    if (! OttoAccess::userCanUseOtto($user)) {
        return false;
    }

    return AgentConversation::query()
        ->where('id', $conversationId)
        ->where('user_id', $user->id)
        ->exists();
});

Broadcast::channel('otto.{userId}.{conversationId}', function (User $user, $userId, string $conversationId) {
    $userId = (int) $userId;

    if ($userId <= 0 || (int) $user->id !== $userId) {
        return false;
    }

    if (! OttoAccess::userCanUseOtto($user)) {
        return false;
    }

    return AgentConversation::query()
        ->where('id', $conversationId)
        ->where('user_id', $user->id)
        ->exists();
});
