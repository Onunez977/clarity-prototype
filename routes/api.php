<?php

use App\Http\Controllers\Api\ClarityScoutAnalyticsController;
use App\Http\Controllers\Api\ClarityScoutAuthController;
use App\Http\Controllers\Api\ClarityScoutDashboardController;
use App\Http\Controllers\Api\ClarityScoutMapController;
use App\Http\Controllers\Api\ClarityScoutNotificationsController;
use App\Http\Controllers\Api\ClarityScoutScanDispositionController;
use App\Http\Controllers\Api\ClarityScoutShiftController;
use App\Http\Controllers\Api\ClarityScoutTripsController;
use App\Http\Controllers\Api\ClarityScoutZonesController;
use App\Http\Controllers\Api\Qa;
use App\Http\Middleware\ClarityScoutMiddleware;
use App\Http\Middleware\TestingWorkflowMiddleware;
use Illuminate\Broadcasting\BroadcastController;

if (config('app.experimental.clarity_scout', false)) {
    // Broadcast auth endpoint for the Scout PWA's Laravel Echo client
    // (Sanctum). Session Echo for Otto uses POST /broadcasting/auth from
    // withBroadcasting() (catch-all is registered later in AppServiceProvider
    // booted — see PG-033). This /api copy exists because Scout authenticates
    // with a bearer token, not a session.
    Route::post('broadcasting/auth', [BroadcastController::class, 'authenticate'])
        ->middleware('auth:sanctum')
        ->name('broadcasting.auth');

    Route::prefix('scout')->group(function () {
        Route::post('login', [ClarityScoutAuthController::class, 'login'])->middleware('throttle:5,1');
        Route::post('forgot-password', [ClarityScoutAuthController::class, 'forgotPassword'])->middleware('throttle:5,1');

        Route::middleware(['auth:sanctum', ClarityScoutMiddleware::class])->group(function () {
            Route::post('logout', [ClarityScoutAuthController::class, 'logout']);
            Route::get('me', [ClarityScoutAuthController::class, 'me']);

            Route::get('dashboard/stats', [ClarityScoutDashboardController::class, 'stats']);
            Route::get('dashboard/recent-hits', [ClarityScoutDashboardController::class, 'recentHits']);
            Route::get('dashboard/hotlist-stats', [ClarityScoutDashboardController::class, 'hotlistStats']);
            Route::get('dashboard/check-in-counts', [ClarityScoutDashboardController::class, 'checkInCounts']);

            // Per-user rate limit on the ClickHouse-backed map endpoints (kit-position
            // and live-path are uncached + polled). 120/min clears the ~6/min poll.
            Route::middleware('throttle:120,1')->group(function () {
                Route::get('map/scan-paths', [ClarityScoutMapController::class, 'scanPaths']);
                Route::get('map/hits', [ClarityScoutMapController::class, 'hits']);
                Route::get('map/heatmap/hits', [ClarityScoutMapController::class, 'heatmapHits']);
                Route::get('map/heatmap/orders', [ClarityScoutMapController::class, 'heatmapOrders']);
                Route::get('map/kit-position', [ClarityScoutMapController::class, 'kitPosition']);
                Route::get('map/live-path', [ClarityScoutMapController::class, 'livePath']);
                Route::get('map/teammate-paths', [ClarityScoutMapController::class, 'teammatePaths']);
            });

            Route::get('map/zones', [ClarityScoutZonesController::class, 'index']);

            Route::get('trips/current', [ClarityScoutTripsController::class, 'current']);
            Route::get('trips/browse', [ClarityScoutTripsController::class, 'browse']);
            Route::get('trips/day/{date}', [ClarityScoutTripsController::class, 'day']);
            Route::get('trips/{date}/{tripIndex}', [ClarityScoutTripsController::class, 'show']);

            Route::get('shift/status', [ClarityScoutShiftController::class, 'status']);
            Route::post('shift/end', [ClarityScoutShiftController::class, 'end'])->middleware('throttle:10,1');

            Route::post('scan/disposition', ClarityScoutScanDispositionController::class)->middleware('throttle:30,1');

            Route::get('notifications', [ClarityScoutNotificationsController::class, 'index']);
            Route::post('notifications/read-all', [ClarityScoutNotificationsController::class, 'markAllRead']);
            Route::post('notifications/{notification}/read', [ClarityScoutNotificationsController::class, 'markRead']);

            Route::post('track', ClarityScoutAnalyticsController::class)->middleware('throttle:120,1');
        });
    });
}

Route::group(['prefix' => 'v1'], function () {
    if (config('app.env') !== 'production') {
        Route::group([
            'middleware' => [TestingWorkflowMiddleware::class],
            'prefix' => 'qa/workflow',
            'as' => 'qa.workflow.',
        ], function () {
            Route::group(['prefix' => 'scans', 'as' => 'scans.'], function () {
                Route::post('/create', [Qa\ScanController::class, 'create'])->name('create');
            });

            Route::group(['prefix' => 'hotlists', 'as' => 'hotlists.'], function () {
                Route::post('/create', [Qa\HotlistController::class, 'create'])->name('create');
            });

            Route::group(['prefix' => 'hits', 'as' => 'hits.'], function () {
                Route::post('/create', [Qa\HitController::class, 'create'])->name('create');
            });
        });
    }
});
