<?php

use App\Http\Middleware\DropInvalidBroadcastSocketId;
use App\Http\Middleware\ForceHttpsRequest;
use App\Http\Middleware\OrgMiddleware;
use App\Http\Middleware\PrototypeBadge;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Session Echo auth (Otto). Must not be swallowed by the web `{any}`
    // catch-all — that catch-all is registered in AppServiceProvider on a
    // *later* booted callback so this Broadcast::routes() wins. See PG-033.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust ALB / kamal-proxy forwarded proto/host so absolute signed URLs
        // and URL generation match the public https origin.
        $middleware->trustProxies(at: '*');
        $middleware->prepend(ForceHttpsRequest::class);
        $middleware->append(DropInvalidBroadcastSocketId::class);
        $middleware->append(OrgMiddleware::class);
        $middleware->append(PrototypeBadge::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {

        $exceptions->report(function (QueryException $e) {
            if ($e->getCode() === '57014' || str_contains($e->getMessage(), 'statement timeout')) {
                // Only log the generic Datadog count for uncaught DB query exceptions
                logDatadogCount('clarity.query_timeout', 1, [
                    'category' => 'database_timeout',
                    'item' => 'generic_query_timeout',
                ]);
            }
        });
    })
    ->create();
