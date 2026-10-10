<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInsightLprAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->user()?->isInsightLpr() || session()->missing('admin_impersonator')) {
            abort(403);
        }

        return $next($request);
    }
}
