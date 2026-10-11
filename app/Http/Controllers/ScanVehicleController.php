<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Hit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScanVehicleController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['scan_id' => 'required|integer']);

        $scanId = (int) $request->input('scan_id');
        $orgId = (int) $request->user()->org_id;

        $cacheKey = 'scan_vehicle:'.$orgId.':'.$scanId;

        // The ClickHouse table carries no org column, so the tenant boundary is kit
        // ownership. withShared matches ListScans: a scan the list shows from a shared
        // kit must still resolve its vehicle. This is a different axis from the Postgres
        // fallback below, which scopes by who owns the ORDER (hotlist.org_id), not the
        // camera - the two sets are deliberately not the same.
        //
        // scans_metadata_extracted.kit_id is Nullable(Int32) and `NULL IN (...)` is NULL,
        // so rows the denormalization left without a kit_id resolve no vehicle here and
        // fall through to the hotlist path. That fails CLOSED on purpose: the alternative
        // (kit_id IS NULL passing the filter) would expose those rows to every org.
        $kitIds = $request->user()->getUserKits(withShared: true)->pluck('id')->all();

        $vehicle = cache()->remember($cacheKey, now()->addMinutes(15), function () use ($scanId, $orgId, $kitIds) {
            if ($kitIds !== [] && (config('app.experimental.clickhouse.scans', false) || session()->has('admin_impersonator'))) {
                try {
                    $kitIdList = \implode(',', \array_map('\intval', $kitIds));

                    $metadata = DB::connection('clarity_clickhouse')->selectOne("
                        SELECT vehicle_color, vehicle_make, vehicle_type
                        FROM zod.scans_metadata_extracted
                        WHERE scan_id = {$scanId}
                          AND kit_id IN ({$kitIdList})
                          AND _peerdb_is_deleted = 0
                        LIMIT 1
                    ");

                    if ($metadata) {
                        $vehicle = \trim(\html_entity_decode(
                            ($metadata['vehicle_color'] ?? '').' '.($metadata['vehicle_make'] ?? '').' '.($metadata['vehicle_type'] ?? '')
                        ));

                        if ($vehicle !== '') {
                            return $vehicle;
                        }
                    }
                } catch (\Throwable $e) {
                    //
                }
            }

            $hit = Hit::query()
                ->where('hits.scan_id', $scanId)
                ->join('hotlist', 'hotlist.id', '=', 'hits.hotlist_id')
                ->where('hotlist.org_id', $orgId)
                ->select([
                    'hotlist.vehicle_year',
                    'hotlist.vehicle_make',
                    'hotlist.vehicle_model',
                ])
                ->first();

            return $hit
                ? \trim(\html_entity_decode($hit->vehicle_year.' '.$hit->vehicle_make.' '.$hit->vehicle_model))
                : '';
        });

        return response()->json(['vehicle' => $vehicle]);
    }
}
