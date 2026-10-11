<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Scan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FlexMatchHotlistsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['scan_id' => 'required|integer']);

        $scanId = (int) $request->input('scan_id');
        $orgId = (int) $request->user()->org_id;

        $scan = Scan::query()
            ->where('id', $scanId)
            ->whereHas('kit', fn ($q) => $q->where('org_id', $orgId))
            ->firstOrFail();

        $hotlists = DB::connection('zod')->table('hotlist as h')
            ->join('kits as k', function ($join) use ($scan) {
                $join->on('k.org_id', '=', 'h.org_id')
                    ->where('k.id', '=', $scan->kit_id);
            })
            ->whereRaw('h.plate = ANY(public.get_license_plate_variants(?))', [$scan->ocr])
            ->whereRaw('h.plate != ?', [$scan->ocr])
            ->where('h.status', 1)
            ->whereNotExists(function ($query) use ($scanId) {
                $query->select(DB::raw(1))
                    ->from('hits')
                    ->whereColumn('hits.hotlist_id', 'h.id')
                    ->where('hits.scan_id', $scanId);
            })
            ->select([
                'h.id',
                'h.plate',
                'h.us_state',
                'h.vin',
                'h.vehicle_year',
                'h.vehicle_make',
                'h.vehicle_model',
                'h.remote_order_id',
            ])
            ->get();

        return response()->json(['hotlists' => $hotlists]);
    }
}
