<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Clickhouse\Queries\LprVisitCountQuery;
use App\Enums\FeatureEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

class LprVisitCountController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (Feature::for($request->user())->inactive(FeatureEnum::LPR_VISITS->slug()) && session()->missing('admin_impersonator')) {
            return response()->json([]);
        }

        $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
        ]);

        $result = LprVisitCountQuery::query([
            'lat' => $request->input('lat'),
            'lng' => $request->input('lng'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'org_id' => $request->user()->org_id,
            'timezone' => $request->user()->timezone ?? 'UTC',
        ], cache: true);

        if (($result['lpr_visits'] ?? 0) === 0) {
            $result['last_visit'] = null;
        }

        if (! empty($result['last_visit'])) {
            $result['last_visit'] = Carbon::parse($result['last_visit'], 'UTC')
                ->setTimezone($request->user()->timezone ?? 'UTC')
                ->format('n/j/Y @ g:ia');
        }

        return response()->json($result);
    }
}
