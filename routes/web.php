<?php

use App\Enums\FeatureEnum;
use App\Filament\Pages\Subscription;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Api\ClarityScoutBirdsEyeController;
use App\Http\Controllers\Api\ClarityScoutBirdsEyeDispositionController;
use App\Http\Controllers\Auth\ScoutResetPasswordController;
use App\Http\Controllers\Auth\ScoutSetPasswordController;
use App\Http\Controllers\BackyardController;
use App\Http\Controllers\ClarityScoutForceLoginController;
use App\Http\Controllers\DispositionController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\FlexMatchAlertController;
use App\Http\Controllers\FlexMatchHotlistsController;
use App\Http\Controllers\GeocoderController;
use App\Http\Controllers\ImpersonateController;
use App\Http\Controllers\InviteRedemptionController;
use App\Http\Controllers\KitUserPathController;
use App\Http\Controllers\LprVisitCountController;
use App\Http\Controllers\NearestGivenAddressController;
use App\Http\Controllers\Otto\ConversationController;
use App\Http\Controllers\Otto\InvestigationDataController;
use App\Http\Controllers\ScanVehicleController;
use App\Http\Controllers\UserDtiController;
use App\Http\Controllers\ZoneIndexController;
use App\Http\Controllers\ZoneZipSearchController;
use App\Http\Middleware\ClarityScoutAdminMiddleware;
use App\Http\Middleware\OrgMiddleware;
use App\Http\Middleware\ValidateScoutSignedUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Http\Middleware\Sample;

Route::redirect('/', '/app/login')->name('login');
Route::view('/billing', 'prototype.billing-stub')->name('billing');

Route::controller(InviteRedemptionController::class)->middleware('throttle:5,1')->group(function () {
    Route::get('client/invite/redeem', 'show')->name('client.invite.redeem.show');
    Route::post('client/invite/redeem', 'redeem')->name('client.invite.redeem');
});

Route::middleware(Sample::rate(0))->group(function () {
    Route::controller(ImpersonateController::class)->group(function () {
        Route::get('auth/impersonate', 'impersonate')->name('impersonate');
        Route::get('auth/impersonate/leave', 'leave')->name('impersonate.leave');
        Route::post('auth/impersonate/exit-admin', 'exitAdminMode')->name('impersonate.exit-admin');
    });

    Route::controller(BackyardController::class)->group(function () {
        Route::get('backyard/horizon', 'viewHorizon')->name('backyard.horizon');
        Route::get('backyard/pennant/clear', 'pennantClear')->name('backyard.pennant.clear');
        Route::get('backyard/run-area/materialize', 'materializeRunArea')->name('backyard.run-area.materialize');
    });
});

Route::controller(EmailController::class)->group(function () {
    Route::get('email/unsubscribe/alert', 'unsubscribeFromAlert')->name('email.unsubscribe.alert');
    Route::get('email/unsubscribe/all', 'unsubscribeFromAllAlerts')->name('email.unsubscribe.all');
});

Route::middleware('auth')->group(function () {
    Route::middleware(Sample::rate(0))->group(function () {
        Route::post('/user/dti', UserDtiController::class)->name('user.dti');

        Route::controller(GeocoderController::class)->group(function () {
            Route::post('geocode', 'geocode')->name('api.geocode');
            Route::post('geocode/reverse', 'reverse')->name('api.geocode.reverse');
        });
    });

    Route::prefix('otto')->group(function () {
        Route::get('investigation/{conversationId}', InvestigationDataController::class)
            ->name('otto.investigation');

        Route::get('conversations', [ConversationController::class, 'index'])
            ->name('otto.conversations.index');
        Route::post('conversations', [ConversationController::class, 'store'])
            ->name('otto.conversations.store');
        Route::post('conversations/reset', [ConversationController::class, 'reset'])
            ->name('otto.conversations.reset');
        Route::post('disclaimer', [ConversationController::class, 'acceptDisclaimer'])
            ->name('otto.disclaimer');
        Route::get('conversations/{id}', [ConversationController::class, 'show'])
            ->name('otto.conversations.show');
        Route::post('conversations/{id}/messages', [ConversationController::class, 'storeMessage'])
            ->name('otto.conversations.messages.store');
        Route::post('conversations/{id}/messages/{messageId}/feedback', [ConversationController::class, 'feedback'])
            ->name('otto.conversations.messages.feedback');
        Route::post('conversations/{id}/poll', [ConversationController::class, 'poll'])
            ->name('otto.conversations.poll');
        Route::post('conversations/{id}/stop', [ConversationController::class, 'stop'])
            ->name('otto.conversations.stop');
        Route::post('conversations/{id}/disclaimer', [ConversationController::class, 'acceptDisclaimer'])
            ->name('otto.conversations.disclaimer');
        Route::post('conversations/{id}/zone-create', [ConversationController::class, 'applyZoneCreate'])
            ->name('otto.conversations.zone-create');
        Route::post('conversations/{id}/zone-update', [ConversationController::class, 'applyZoneUpdate'])
            ->name('otto.conversations.zone-update');
        Route::post('conversations/{id}/open-zone-editor', [ConversationController::class, 'openZoneEditor'])
            ->name('otto.conversations.open-zone-editor');
    });

    Route::get('upgrade/from/{feature}', function (string $feature) {
        if (! \in_array($feature, FeatureEnum::slugs(), true)) {
            return redirect()->back();
        }

        $sources = session('upgrade_sources', []);
        if (! \in_array($feature, $sources, true)) {
            session()->push('upgrade_sources', $feature);
        }

        return redirect(Subscription::getUrl());
    })->name('upgrade.from');

    Route::post('/analytics/track', AnalyticsController::class)->name('analytics.track');

    Route::prefix('zones')->middleware(OrgMiddleware::class)->group(function () {
        Route::get('/zip-search', ZoneZipSearchController::class)->name('zones.zip-search');
        Route::get('/all', ZoneIndexController::class)->name('zones.all');
    });

    Route::post('/scan/disposition', DispositionController::class)->name('scan.disposition');
    Route::post('/scan/vehicle', ScanVehicleController::class)->name('scan.vehicle');
    Route::post('/scan/flex-match-hotlists', FlexMatchHotlistsController::class)->name('scan.flex-match-hotlists');
    Route::post('/scan/flex-match-alert', FlexMatchAlertController::class)->name('scan.flex-match-alert');
    Route::post('/lpr-visit-count', LprVisitCountController::class)->name('lpr-visit-count');
    Route::post('/nearest-given-address', NearestGivenAddressController::class)->name('nearest-given-address');

    Route::controller(KitUserPathController::class)->middleware(OrgMiddleware::class)->group(function () {
        Route::post('kit-user-path/paths', 'paths')->name('kit-user-path.paths');
        Route::post('kit-user-path/geojson', 'geojson')->name('kit-user-path.geojson');
        Route::post('kit-user-path/hit-density', 'hitDensity')->name('kit-user-path.hit-density');
    });
});

if (config('app.experimental.clarity_scout', false)) {
    Route::get('scout/force-login/{kit}', ClarityScoutForceLoginController::class)->middleware('auth')->name('clarity-scout.force-login');

    Route::middleware(ValidateScoutSignedUrl::class)->group(function () {
        Route::get('scout/set-password/{user}', [ScoutSetPasswordController::class, 'show'])
            ->name('scout.set-password');
        Route::post('scout/set-password/{user}', [ScoutSetPasswordController::class, 'update'])
            ->name('scout.set-password.store');

        Route::get('scout/reset-password/{user}', [ScoutResetPasswordController::class, 'show'])
            ->name('scout.reset-password');
        Route::post('scout/reset-password/{user}', [ScoutResetPasswordController::class, 'update'])
            ->name('scout.reset-password.store');
    });

    // Scout Bird's Eye — admin-only, org-wide view (DEV-2116). Registered ABOVE
    // the driver catch-all so scout/org-map/* never falls through to the driver
    // SPA. Web-session auth (the admin is logged into the panel) + the admin/Scout
    // gate; the trailing {any?} lets Vue Router own client-side navigation.
    Route::middleware(['auth', ClarityScoutAdminMiddleware::class])
        ->prefix('scout/org-map')
        ->group(function () {
            // Admin API mirrors the driver /api/scout/* paths + response shapes so the
            // real Scout Pinia stores consume it unchanged (DEV-2116, rev 2). Org-wide,
            // optionally narrowed by a kit_ids selector.
            Route::prefix('api')->group(function () {
                Route::get('drivers', [ClarityScoutBirdsEyeController::class, 'drivers']);

                Route::get('map/scan-paths', [ClarityScoutBirdsEyeController::class, 'scanPaths']);
                Route::get('map/hits', [ClarityScoutBirdsEyeController::class, 'hits']);
                Route::get('map/heatmap/hits', [ClarityScoutBirdsEyeController::class, 'heatmapHits']);
                Route::get('map/heatmap/orders', [ClarityScoutBirdsEyeController::class, 'heatmapOrders']);
                Route::get('map/zones', [ClarityScoutBirdsEyeController::class, 'zones']);
                // Org-wide analogues of the single-kit kit-position/live-path (one per driver).
                Route::get('map/driver-positions', [ClarityScoutBirdsEyeController::class, 'driverPositions']);
                Route::get('map/driver-paths', [ClarityScoutBirdsEyeController::class, 'driverPaths']);

                Route::get('dashboard/stats', [ClarityScoutBirdsEyeController::class, 'stats']);
                Route::get('dashboard/recent-hits', [ClarityScoutBirdsEyeController::class, 'recentHits']);

                Route::get('settings/map-layers', [ClarityScoutBirdsEyeController::class, 'mapLayers']);
                Route::put('settings/map-layers', [ClarityScoutBirdsEyeController::class, 'updateMapLayers']);

                Route::post('scan/disposition', ClarityScoutBirdsEyeDispositionController::class)->middleware('throttle:30,1');

                // Any other /api/* path (e.g. a driver-only endpoint with no admin
                // mirror) must 404 as JSON rather than fall through to the SPA
                // catch-all below, which would serve HTML and break response.json()
                // on the client (DEV-2184).
                Route::get('{any}', fn () => response()->json(['message' => 'Not Found.'], 404))->where('any', '.*');
            });

            Route::get('{any?}', fn () => view('clarity-scout-birds-eye'))
                ->where('any', '.*')
                ->name('clarity-scout.org-map');
        });

    Route::get('scout/{any?}', fn () => view('clarity-scout'))->where('any', '.*')->name('clarity-scout');
}

/*
 * QA force-login bypass for local Herd sites (e.g. qa.platform.test).
 *
 * Safe to commit. Four independent guards — each one alone would shut the
 * door in any other environment:
 *   1. Registration is wrapped in app()->environment('local'), so the route
 *      literally does not exist in staging/production.
 *   2. config('app.dev_login_enabled') defaults to false; the DEV_LOGIN_ENABLED
 *      env var is only set in local .env files.
 *   3. Runtime re-check requires the request to originate from a loopback IP.
 *   4. Runtime re-check requires the host to end in .test.
 *
 * For an attacker to exploit this in production, all four guards would have
 * to fail simultaneously. If that happens, this route is the least of your
 * worries.
 */
if (app()->environment('local') && config('app.dev_login_enabled') === true) {
    Route::get('__qa/login/{id?}', function (Request $request, int $id = 1) {
        abort_unless(app()->environment('local'), 404);
        abort_unless(config('app.dev_login_enabled') === true, 404);
        // Loopback (Herd) or private/docker bridge (Lerd nginx → PHP-FPM).
        $ip = $request->ip();
        $isLocalIp = in_array($ip, ['127.0.0.1', '::1'], true)
            || str_starts_with($ip, '10.')
            || str_starts_with($ip, '192.168.')
            || preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $ip) === 1;
        abort_unless($isLocalIp, 404);
        $host = $request->getHost();
        abort_unless(
            str_ends_with($host, '.test')
            || in_array($host, ['localhost', '127.0.0.1'], true),
            404
        );

        Auth::loginUsingId($id);

        // Mirror the ImpersonateController flow so downstream admin-impersonator
        // gates (Pro feature toggles, Scout force-login action, Horizon, Exit
        // Admin Mode banner, etc.) are reachable from a QA login. Safe — same
        // env+host guards above apply. Both session keys are required: the
        // filament impersonate banner gates on `impersonator`, while force-login
        // and Exit Admin Mode gate on `admin_impersonator`.
        $user = Auth::user();
        abort_unless($user !== null, 404);

        $qaImpersonator = [
            'id' => $user->getKey(),
            'name' => $user->name ?? 'QA Admin',
        ];
        session()->put('impersonator', $qaImpersonator);
        session()->put('admin_impersonator', $qaImpersonator);

        return redirect('/app');
    })->middleware('throttle:10,1')->where('id', '[0-9]+');
}

// Unknown-path catch-all is registered in AppServiceProvider on a booted
// callback *after* withBroadcasting(), so POST /broadcasting/auth and
// GET /__qa/login are not swallowed. See PG-033.
