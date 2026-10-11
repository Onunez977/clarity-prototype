<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FeatureEnum;
use App\Facades\Analytics;
use App\Http\Requests\ScanDispositionRequest;
use App\Models\Scan;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

class DispositionController extends Controller
{
    public function __invoke(ScanDispositionRequest $request)
    {
        try {
            $scan = Scan::query()
                ->where('id', $request->input('scan_id'))
                ->forOrg(auth()->user()->org_id, withSharedKits: Feature::active(FeatureEnum::SHARED_KITS->slug()))
                ->firstOrFail();

            $scan?->updateDisposition(
                disposition: $request->input('disposition'),
                user: $request->user(),
                reason: $request->input('reason'),
            );

            Analytics::track('disposition-saved', $request->user(), [
                'scan_id' => $request->input('scan_id'),
                'disposition' => $request->input('disposition'),
                'reason' => $request->input('reason'),
                'from_scout' => false,
            ]);

            return response(status: 200);
        } catch (\Exception $exception) {
            Log::error('Failed to update scan disposition', [
                'scan_id' => $request->input('scan_id'),
                'disposition' => $request->input('disposition'),
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response(status: 500);
        }
    }
}
