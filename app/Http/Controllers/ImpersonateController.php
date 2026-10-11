<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Scout\ScoutForceTokens;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InsightLPR\BlackAdam\BlackAdam;

class ImpersonateController extends Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    public function impersonate(Request $request): RedirectResponse
    {
        if (! BlackAdam::validateSignedUrl($request->fullUrl())) {
            abort(401, 'Invalid Signature');
        }

        $this->validate($request, [
            'user_id' => 'required|int',
            'impersonator' => 'required',
        ]);

        if (auth()->check()) {
            ScoutForceTokens::revokeTracked();

            Filament::auth()->logout();
            session()->invalidate();
            session()->regenerateToken();
        }

        Auth::loginUsingId($request->user_id, true);

        session()->put('impersonator', $request->impersonator);

        $admins = [
            1, // Eric
            3, // Jeff
            10, // Oscar
            50, // Dalton
            25, // Andrew
            41, // Josh
            35, // Yolanda
            8, // Jon Armstrong
            45, // Matthew Sheils
            48, // Jennifer Torres
        ];

        if (\in_array(($request->impersonator['id'] ?? false), $admins)) {
            session()->put('admin_impersonator', $request->impersonator);
        }

        $activity = activity('impersonation');

        if ($impersonator = User::find($request->user_id)) {
            $activity->performedOn($impersonator);
        }

        $activity
            ->log('Impersonation session started by '.data_get($request, 'impersonator.name'));

        return redirect()->route('login');

    }

    public function leave(Request $request): RedirectResponse
    {
        $activity = activity('impersonation');

        if ($user = $request->user()) {
            $activity->performedOn($user);
        }

        $activity->log('Impersonation session ended by '.session()->get('impersonator.name'));

        ScoutForceTokens::revokeTracked();

        Filament::auth()->logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect(config('black-adam.BACKYARD_URL').'/admin/platform-users');
    }

    public function exitAdminMode(Request $request): RedirectResponse
    {
        $activity = activity('impersonation');

        if ($user = $request->user()) {
            $activity->performedOn($user);
        }

        $activity
            ->withProperties([
                'action' => 'exit_admin_mode',
                'impersonator' => session('admin_impersonator'),
            ])
            ->log('Admin mode ended by '.data_get(session('admin_impersonator'), 'name', 'staff'));

        ScoutForceTokens::revokeTracked();

        session()->forget('admin_impersonator');

        Notification::make()
            ->warning()
            ->title('Admin mode ended')
            ->body('Scout force-login is unavailable until you re-enter admin mode from Backyard.')
            ->send();

        return redirect()->back();
    }
}
