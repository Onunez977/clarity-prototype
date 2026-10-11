<?php

namespace App\Support\Hits;

use App\Clickhouse\Models\Hit as ClickhouseHit;
use App\Enums\FeatureEnum;
use App\Enums\HitTypeEnum;
use App\Enums\HotlistStatusEnum;
use App\Models\Hit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use InsightLPR\BlackAdam\Enum\HitType;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;
use Laravel\Pennant\Feature;

class HitsListQuery
{
    public static function shouldUseClickhouse(): bool
    {
        return config('app.experimental.clickhouse.hits', false)
            || session()->has('admin_impersonator');
    }

    public static function baseQuery(User $user, ?string $windowStart = null, ?int $status = null): Builder
    {
        $status ??= HotlistStatusEnum::Open->value;

        if (self::shouldUseClickhouse()) {
            return self::buildClickhouseQuery($user, $windowStart, $status);
        }

        return self::buildPostgresQuery($user, $status);
    }

    private static function buildClickhouseQuery(User $user, ?string $windowStart, int $status): Builder
    {
        $kitIds = $user->getUserKits()->pluck('id')->toArray();

        $useHot = $windowStart && Carbon::parse($windowStart)->diffInDays(now()) <= 90;
        $model = $useHot ? ClickhouseHit::hot() : new ClickhouseHit;

        // FINAL on hot only: collapses stale status re-emissions/duplicate rows for ~+5 ms; on cold it costs ~3x memory (DEV-2614 benchmark).
        return $model->newQuery()
            ->when($useHot, fn (Builder $q) => $q->final())
            ->notNicb()
            ->forKits($kitIds)
            ->select([
                'id', 'hotlist_id', 'scan_id',
                'hit_type as type',
                'hit_created_at as created_at',
                'ocr as scans_ocr',
                'scanned_at as scans_scanned_at',
                'camera_name as scans_camera_name',
                'scan_us_state as scans_us_state',
                'scan_guid as scans_guid',
                'kit_id', 'kit_name', 'kit_org_id',
                'lat', 'lng',
                'address',
                'disposition',
                'hotlist_us_state', 'hotlist_plate', 'hotlist_status',
                'hotlist_vin',
                'vehicle_year as hotlist_vehicle_year',
                'vehicle_make as hotlist_vehicle_make',
                'vehicle_model as hotlist_vehicle_model',
                'hotlist_org_id',
                'remote_order_id as hotlist_remote_order_id',
                'integration_id as hotlist_integration_id',
                'client_org_id', 'client_org_name',
                'lienholder_org_id', 'lienholder_org_name',
            ])
            ->when(
                $status == HotlistStatusEnum::Repossessed->value,
                fn ($q) => $q->whereRaw("hit_type NOT IN ('".HitTypeEnum::Staging_Live->value."', '".HitType::Staging_Historical->value."', '".HitType::OverWatch->value."')"),
                fn ($q) => $q->whereRaw("hit_type != '".HitType::OverWatch->value."'"),
            );
    }

    private static function buildPostgresQuery(User $user, int $status): Builder
    {
        return Hit::withoutGlobalScopes()
            ->join('hotlist', 'hotlist.id', '=', 'hits.hotlist_id')
            ->join('scans', 'scans.id', '=', 'hits.scan_id')
            ->join('kits', 'kits.id', '=', 'scans.kit_id')
            ->join('scans_metadata', 'scans_metadata.scan_id', '=', 'hits.scan_id')
            ->join('orgs as client_orgs', 'hotlist.client_org_id', '=', 'client_orgs.id')
            ->join('orgs as lienholder_orgs', 'hotlist.lienholder_org_id', '=', 'lienholder_orgs.id')
            ->select([
                'hits.id as id',
                'hits.*',
                'scans.id as scan_id',
                'scans.ocr as scans_ocr',
                'scans.scanned_at as scans_scanned_at',
                'scans.camera_name as scans_camera_name',
                'scans.us_state as scans_us_state',
                'scans.guid as scans_guid',
                'scans_metadata.meta as scan_metadata',
                'kits.name as kit_name',
                'kits.org_id as kit_org_id',
                'hotlist.us_state as hotlist_us_state',
                'hotlist.plate as hotlist_plate',
                'hotlist.status as hotlist_status',
                'hotlist.vin as hotlist_vin',
                'hotlist.org_id as hotlist_org_id',
                'hotlist.remote_order_id as hotlist_remote_order_id',
                'hotlist.integration_id as hotlist_integration_id',
                'hotlist.vehicle_year as hotlist_vehicle_year',
                'hotlist.vehicle_make as hotlist_vehicle_make',
                'hotlist.vehicle_model as hotlist_vehicle_model',
                'client_org_id',
                'client_orgs.name as client_org_name',
                'lienholder_org_id',
                'lienholder_orgs.name as lienholder_org_name',
            ])
            ->selectRaw('ST_asGeoJson(scans.geo) as scans_geo')
            ->selectRaw('ST_Y(scans.geo::geometry) as lat')
            ->selectRaw('ST_X(scans.geo::geometry) as lng')
            ->when(
                Feature::active(FeatureEnum::HIT_ADDRESS_DISTANCE->slug()) || session()->has('admin_impersonator'),
                fn (Builder $q) => $q
                    ->selectRaw('
                        (SELECT ROUND((ST_DistanceSphere(scans.geo::geometry, a.geo::geometry) * 0.000621371)::numeric, 2)
                         FROM hotlist_address ha
                         JOIN address a ON a.id = ha.address_id
                         WHERE ha.hotlist_id = hotlist.id
                           AND a.geo IS NOT NULL AND scans.geo IS NOT NULL
                         ORDER BY ST_DistanceSphere(scans.geo::geometry, a.geo::geometry) ASC
                         LIMIT 1) as distance_from_given_miles
                    ')
                    ->selectRaw("
                        (SELECT CONCAT_WS(', ',
                            NULLIF(CONCAT_WS(' ', a.house_number, a.road), ''),
                            a.city, CONCAT_WS(' ', a.state, a.postcode))
                         FROM hotlist_address ha
                         JOIN address a ON a.id = ha.address_id
                         WHERE ha.hotlist_id = hotlist.id
                           AND a.geo IS NOT NULL AND scans.geo IS NOT NULL
                         ORDER BY ST_DistanceSphere(scans.geo::geometry, a.geo::geometry) ASC
                         LIMIT 1) as nearest_given_address
                    ")
            )
            ->where('hotlist.integration_id', '!=', IntegrationEnum::NICB->value)
            ->whereIntegerInRaw('hits.kit_id', $user->getUserKits()->pluck('id'))
            ->when(
                $status == HotlistStatusEnum::Repossessed->value,
                fn ($subQuery) => $subQuery->whereNotIn('hits.hit_type', [HitTypeEnum::Staging_Live->value, HitType::Staging_Historical->value, HitType::OverWatch->value]),
                fn ($subQuery) => $subQuery->where('hits.hit_type', '!=', HitType::OverWatch->value),
            );
    }
}
