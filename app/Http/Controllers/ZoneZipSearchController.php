<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FeatureEnum;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

class ZoneZipSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(Feature::active(FeatureEnum::CLARITY_SCOUT->slug()) || session()->has('admin_impersonator'), Response::HTTP_FORBIDDEN);

        Gate::authorize('viewAny', Zone::class);

        $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:5'],
        ]);

        return response()->json(
            Zone::searchZipCodes($request->string('q')->toString())
        );
    }
}
