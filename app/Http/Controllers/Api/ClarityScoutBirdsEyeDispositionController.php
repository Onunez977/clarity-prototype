<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\DispositionEnum;
use App\Enums\FeatureEnum;
use App\Enums\ScoutAnalyticsPage;
use App\Facades\Analytics;
use App\Http\Controllers\Controller;
use App\Http\Requests\ScoutScanDispositionRequest;
use App\Models\Scan;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

class ClarityScoutBirdsEyeDispositionController extends Controller
{
    public function __invoke(ScoutScanDispositionRequest $request): Response
    {
        // Kept outside the try/catch (unlike DispositionController) so a scan
        // outside the admin's own org renders Laravel's standard 404 rather
        // than being swallowed into a 500 by the catch-all below — tenancy
        // failures here must be unambiguous.
        $scan = Scan::query()
            ->where('id', $request->input('scan_id'))
            ->forOrg((int) auth()->user()->org_id, withSharedKits: Feature::active(FeatureEnum::SHARED_KITS->slug()))
            ->firstOrFail();

        // Mirrors the driver controller: map.js only sends a reason for
        // Invalid Scan, so any other disposition always resolves to 'other'
        // even if a client hand-crafts a review reason, or
        // Scan::updateDisposition() would spuriously create a human-review
        // ScanValidation row for a legitimate admin disposition.
        $reason = $request->input('disposition') === DispositionEnum::INVALID_SCAN->value
            ? ($request->input('reason') ?? 'other')
            : 'other';

        try {
            $scan->updateDisposition(
                disposition: $request->input('disposition'),
                user: $request->user(),
                reason: $reason,
            );
        } catch (\Exception $exception) {
            Log::error('Failed to update scan disposition', [
                'scan_id' => $request->input('scan_id'),
                'disposition' => $request->input('disposition'),
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response(status: 500);
        }

        try {
            Analytics::track('disposition-saved', $request->user(), [
                'scan_id' => $request->input('scan_id'),
                'disposition' => $request->input('disposition'),
                'reason' => $reason,
                'from_scout' => true,
            ], page: ScoutAnalyticsPage::BirdsEye->value);
        } catch (\Exception $exception) {
            Log::warning('Failed to track disposition-saved analytics event', [
                'scan_id' => $request->input('scan_id'),
                'disposition' => $request->input('disposition'),
                'error' => $exception->getMessage(),
            ]);
        }

        return response(status: 200);
    }
}
