<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FeatureEnum;
use App\Facades\Analytics;
use App\Jobs\SendFlexMatchToClearPlanJob;
use App\Models\Scan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;

class FlexMatchAlertController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'scan_id' => 'required|integer',
            'hotlist_ids' => 'required|array|min:1',
            'hotlist_ids.*' => 'integer',
        ]);

        $user = $request->user();
        $isPro = Feature::active(FeatureEnum::CLEARPLAN_RESEND->slug()) || session()->has('admin_impersonator');
        $isAdmin = $user->hasRole(RoleEnum::ADMIN->value);

        if (! $isPro || ! $isAdmin) {
            return response()->json(['message' => 'Unauthorized. Requires Admin role and ClearPlan Pro feature.'], 403);
        }

        $scanId = (int) $request->input('scan_id');
        $hotlistIds = array_map('intval', $request->input('hotlist_ids'));
        $orgId = (int) $user->org_id;

        Scan::query()
            ->where('id', $scanId)
            ->whereHas('kit', fn ($q) => $q->where('org_id', $orgId))
            ->firstOrFail();

        SendFlexMatchToClearPlanJob::dispatch($scanId, $hotlistIds, $user);

        Analytics::track('flex-match-alert-sent', $user, [
            'action' => 'pro-feature',
            'scan_id' => $scanId,
            'hotlist_count' => count($hotlistIds),
        ], page: 'scans');

        activity('flex-match-alert')
            ->causedBy($user)
            ->withProperties([
                'scan_id' => $scanId,
                'hotlist_ids' => $hotlistIds,
            ])
            ->log('Manual flex match alert sent to ClearPlan');

        return response()->json([
            'message' => count($hotlistIds).' hotlist(s) queued for ClearPlan.',
        ]);
    }
}
