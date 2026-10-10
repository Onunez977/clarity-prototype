<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\DispositionEnum;
use App\Facades\Analytics;
use App\Http\Controllers\Controller;
use App\Http\Requests\ScoutScanDispositionRequest;
use App\Models\Scan;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ClarityScoutScanDispositionController extends Controller
{
    public function __invoke(ScoutScanDispositionRequest $request): Response
    {
        // Kept outside the try/catch (unlike DispositionController) so a scan
        // outside the driver's own kit renders Laravel's standard 404 rather
        // than being swallowed into a 500 by the catch-all below — tenancy
        // failures here must be unambiguous.
        $scan = Scan::query()
            ->where('id', $request->input('scan_id'))
            ->where('kit_id', $request->user()->kit_id)
            ->whereNotNull('kit_id')
            ->firstOrFail();

        // Mirrors the web path's app.js default: a reason is only honored for
        // Invalid Scan. Any other disposition always resolves to 'other', even
        // if a client hand-crafts a review reason, so Scan::updateDisposition()
        // never spuriously creates a human-review ScanValidation row for a
        // legitimate driver disposition.
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
            ]);
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
