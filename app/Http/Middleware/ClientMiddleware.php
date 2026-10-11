<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Dashboard;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Symfony\Component\HttpFoundation\Response;

class ClientMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check() || (! auth()->user()->canAccessPanel(Filament::getPanel('client')) && ! session()->has('impersonator'))) {
            return response()->redirectToIntended(Dashboard::getUrl(panel: 'app'));
        }

        if (
            auth()->user()->hasPermissionTo('clarity-scout')
            && ! auth()->user()->hasAnyRole([RoleEnum::ADMIN->value, RoleEnum::USER->value])
            && ! auth()->user()->canAccessPanel(Filament::getPanel('client'))
        ) {
            return redirect('/scout/login');
        }

        return $next($request);
    }
}
