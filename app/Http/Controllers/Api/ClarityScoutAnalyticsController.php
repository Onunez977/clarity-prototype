<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Facades\Analytics;
use App\Http\Controllers\Controller;
use App\Http\Requests\ScoutTrackRequest;
use Illuminate\Http\Response;

class ClarityScoutAnalyticsController extends Controller
{
    public function __invoke(ScoutTrackRequest $request): Response
    {
        Analytics::track(
            $request->validated('feature'),
            $request->user(),
            $request->validated('params') ?? [],
            $request->validated('page'),
        );

        return response()->noContent();
    }
}
