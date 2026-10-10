<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accept absolute or relative signed URLs for Scout onboarding, and render a
 * usable error page instead of Laravel's bare "Invalid signature." 403.
 */
class ValidateScoutSignedUrl
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasValidSignature(absolute: true) || $request->hasValidSignature(absolute: false)) {
            return $next($request);
        }

        $expired = $request->filled('expires')
            && is_numeric($request->query('expires'))
            && now()->getTimestamp() > (int) $request->query('expires');

        return response()->view('auth.scout.invalid-link', [
            'expired' => $expired,
            'flow' => str_contains($request->path(), 'reset-password') ? 'reset' : 'onboarding',
        ], 403);
    }
}
