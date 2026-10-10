<?php

namespace App\Http\Middleware;

use App\Filament\Client\Resources\ClientSearchScansResource\Pages\ListClientSearchScans;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Symfony\Component\HttpFoundation\Response;

class UserMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user?->canAccessPanel(Filament::getPanel('client')) ?? false) {
            return response()->redirectToIntended(ListClientSearchScans::getUrl(panel: 'client'));
        }

        if ($user?->hasPermissionTo('clarity-scout') && $user?->kit_id && ! $user?->hasAnyRole([RoleEnum::ADMIN->value, RoleEnum::USER->value])) {
            return redirect('/scout/login');
        }

        return $next($request);
    }
}
