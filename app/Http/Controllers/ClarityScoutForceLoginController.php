<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Kit;
use App\Support\Scout\ScoutDriverFlags;
use App\Support\Scout\ScoutForceTokens;
use Illuminate\Http\Request;

class ClarityScoutForceLoginController extends Controller
{
    public function __invoke(Request $request, Kit $kit)
    {
        if (! session()->has('admin_impersonator')) {
            activity('clarity-scout-force-login')
                ->withProperties([
                    'outcome' => 'denied',
                    'reason' => 'admin_impersonator_missing',
                    'kit_id' => $kit->id,
                    'kit_org_id' => $kit->org_id,
                    'status' => 403,
                ])
                ->log('Scout force-login denied for kit '.$kit->name.' (#'.$kit->id.'): admin mode ended or session expired');

            abort(
                403,
                'Admin mode is not active. Return to the admin panel and re-enter admin mode, then try Login to Scout again.',
            );
        }

        $user = $kit->assigned_user;

        if (! $user) {
            activity('clarity-scout-force-login')
                ->withProperties([
                    'outcome' => 'denied',
                    'reason' => 'kit_has_no_assigned_user',
                    'kit_id' => $kit->id,
                    'kit_org_id' => $kit->org_id,
                    'status' => 404,
                    'impersonator' => session('admin_impersonator'),
                ])
                ->log('Scout force-login denied for kit '.$kit->name.' (#'.$kit->id.'): no user assigned');

            abort(404, 'No user assigned to this kit.');
        }

        // Minting keeps recently-issued tokens alive and revokes on impersonation
        // exit; see ScoutForceTokens for why neither is done inline here.
        $token = ScoutForceTokens::mintFor($user);

        activity('clarity-scout-force-login')
            ->performedOn($user)
            ->withProperties([
                'kit_id' => $kit->id,
                'kit_org_id' => $kit->org_id,
                'driver_id' => $user->id,
                'impersonator' => session('admin_impersonator'),
            ])
            ->log('Scout force-login into kit '.$kit->name.' (#'.$kit->id.')');

        return view('clarity-scout', [
            'forceAuth' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'org_id' => $user->org_id,
                ],
                'kit' => [
                    'id' => $kit->id,
                    'name' => $kit->name,
                    'kit_mapping' => $kit->kit_mapping,
                ],
                'is_impersonating' => true,
                ...ScoutDriverFlags::for($user, isImpersonating: true),
            ],
        ]);
    }
}
