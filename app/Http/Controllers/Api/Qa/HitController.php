<?php

namespace App\Http\Controllers\Api\Qa;

use App\Events\HitEvent;
use App\Http\Controllers\Controller;
use App\Models\Hit;
use App\Models\Scan;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use InsightLPR\BlackAdam\BlackAdam;
use InsightLPR\BlackAdam\Enum\HitType;

/**
 * @codeCoverageIgnore
 */
class HitController extends Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    public function create(Request $request)
    {
        if (! BlackAdam::validateSignedUrl($request->fullUrl())) {
            abort(401, 'Invalid Signature');
        }

        $hit = Hit::factory()->make([
            'hotlist_id' => $request->input('hotlist_id'),
            'kit_id' => $request->input('kit_id'),
            'scan_id' => $request->input('scan_id'),
            'hit_type' => $request->input('hit_type', HitType::Live->value),
        ]);

        $scan = Scan::find($request->input('scan_id'));
        $hit->setRelation('scan', $scan);

        event(new HitEvent($hit));

        return response([
            'ids' => [$hit],
        ]);
    }
}
