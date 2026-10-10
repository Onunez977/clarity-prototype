<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Facades\Analytics;
use App\Http\Requests\TrackAnalyticsRequest;

class AnalyticsController extends Controller
{
    public function __invoke(TrackAnalyticsRequest $request)
    {
        Analytics::track($request->feature, $request->user(), $request->params ?? [], $request->page);

        return response()->noContent();
    }
}
