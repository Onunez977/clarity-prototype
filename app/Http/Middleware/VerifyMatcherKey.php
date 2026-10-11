<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyMatcherKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.matcher.key');
        $provided = (string) $request->header('X-Matcher-Key', '');

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
