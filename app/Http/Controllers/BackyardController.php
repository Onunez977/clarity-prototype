<?php

namespace App\Http\Controllers;

use App\Jobs\FlushPennant;
use App\Jobs\Org\MaterializeOrgRunArea;
use App\Models\Org;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\BlackAdam;
use Spatie\Permission\PermissionRegistrar;

class BackyardController extends Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    public function pennantClear(Request $request)
    {
        if (! BlackAdam::validateSignedUrl($request->fullUrl())) {
            abort(401, 'Invalid Signature');
        }

        // Clear all Pennant features.
        FlushPennant::dispatch();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        activity('pennant')
            ->log('Pennant Features cleared');

        return response(status: 200);
    }

    public function materializeRunArea(Request $request)
    {
        if (! BlackAdam::validateSignedUrl($request->fullUrl())) {
            abort(401, 'Invalid Signature');
        }

        $org = Org::find($request->integer('org_id'));

        if ($org) {
            MaterializeOrgRunArea::dispatch($org);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        activity('run-area')
            ->log('Run area re-materialization requested for org '.$request->integer('org_id'));

        return response(status: 200);
    }

    public function viewHorizon(Request $request)
    {
        if (! BlackAdam::validateSignedUrl($request->fullUrl())) {
            abort(401, 'Invalid Signature');
        }

        if ($request->has('email') && Str::endsWith($request->get('email'), '@insightlpr.com')) {
            session()->put('backyard_horizon', true);
        }

        return redirect()->to('/horizon');
    }
}
