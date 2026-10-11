<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force the incoming request to report HTTPS so absolute signed URLs
 * (minted with APP_URL / URL::forceScheme('https')) validate behind a
 * TLS-terminating proxy that forwards plain HTTP to the app.
 *
 * Only remaps port 80 → 443. Explicit ports (e.g. :8000) are preserved so
 * absolute signatures that include them still match.
 */
class ForceHttpsRequest
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.force_https')) {
            $request->server->set('HTTPS', 'on');

            // Remap only the default HTTP port (80 → 443). Preserve explicit
            // non-80 ports (e.g. http://127.0.0.1:8000 in local/cloud QA) so
            // absolute signatures minted with that port still validate —
            // overwriting SERVER_PORT to 443 would drop :8000 from the URL
            // and invalidate the signature.
            if ((int) $request->server->get('SERVER_PORT', 80) === 80) {
                $request->server->set('SERVER_PORT', '443');
            }
        }

        return $next($request);
    }
}
