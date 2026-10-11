<?php

namespace App\Http\Controllers\Api\Qa;

use App\Http\Controllers\Controller;
use App\Models\Kit;
use App\Models\Scan;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use InsightLPR\BlackAdam\BlackAdam;

/**
 * @codeCoverageIgnore
 */
class ScanController extends Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    public function create(Request $request)
    {
        if (! BlackAdam::validateSignedUrl($request->fullUrl())) {
            abort(401, 'Invalid Signature');
        }

        $ocrs = collect($request->input('ocrs', []))
            ->map(fn ($ocr) => [\strtoupper($ocr)])
            ->toArray();

        $kit = Kit::find($request->input('kit_id'));
        if (! $kit) {
            $kit = Kit::factory()->create([
                'org_id' => $request->input('org_id'),
            ]);
        }

        $scans = Scan::factory()
            ->count($request->input('count', 1))
            ->sequence($ocrs)
            ->create([
                'kit_id' => $kit->id,
            ]);

        return response([
            'ids' => $scans->pluck('id')->toArray(),
        ]);
    }
}
