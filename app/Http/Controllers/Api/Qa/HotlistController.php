<?php

namespace App\Http\Controllers\Api\Qa;

use App\Http\Controllers\Controller;
use App\Models\Hotlist;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use InsightLPR\BlackAdam\BlackAdam;

/**
 * @codeCoverageIgnore
 */
class HotlistController extends Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    public function create(Request $request)
    {
        if (! BlackAdam::validateSignedUrl($request->fullUrl())) {
            abort(401, 'Invalid Signature');
        }

        $hotlists = Hotlist::factory()
            ->count($request->input('count', 1))
            ->create([
                'org_id' => $request->input('org_id'),
            ]);

        return response([
            'ids' => $hotlists->pluck('id')->toArray(),
        ]);
    }
}
