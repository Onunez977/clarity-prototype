<?php

namespace App\Http\Controllers\Otto;

use App\Clickhouse\Models\ClickhouseModel;
use App\Clickhouse\Models\Hotlist as ClickhouseHotlist;
use App\Clickhouse\Models\Scan;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Datatype\SearchType;
use App\Enums\HotlistStatusEnum;
use App\Filament\Pages\ManageZones;
use App\Http\Controllers\Controller;
use App\Models\AgentInvestigation;
use App\Models\Kit;
use App\Models\KitSettings;
use App\Models\User;
use App\Models\Zone;
use App\Support\Hits\HitsListQuery;
use App\Support\Otto\LimitedToolResultMessage;
use App\Support\Otto\OttoAccess;
use App\Support\Otto\OttoScoutAccess;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use InsightLPR\BlackAdam\Enum\VehicleImageTypeEnum;

class InvestigationDataController extends Controller
{
    public function __invoke(string $conversationId): JsonResponse
    {
        $user = auth()->user();

        abort_unless(OttoAccess::userCanUseOtto($user), 403);

        // Belt: userCanUseOtto() now sets the team id (including impersonation),
        // but the ViewAny_* checks below must not depend on call order.
        if ($user->org_id !== null) {
            setPermissionsTeamId($user->org_id);
        }

        // Those per-type checks are NOT redundant with the tool gates: an
        // investigation keeps the result ids a search recorded, so a role that
        // later loses ViewAny_* would otherwise keep getting those records
        // hydrated on every refresh of the results pane.
        $investigation = AgentInvestigation::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $user->id)
            ->first();

        if (! $investigation) {
            return response()->json(['searches' => [], 'scans' => [], 'hits' => [], 'hotlists' => [], 'drivers' => [], 'zones' => []]);
        }

        $properties = $investigation->properties;

        $searches = collect($properties->searches)->map(fn ($search) => [
            'searchId' => $search->searchId,
            'title' => $search->title ?: $search->searchType->value.' search',
            'searchType' => $search->searchType->value,
        ])->values()->all();

        $scanSearchIds = collect($properties->searches)
            ->filter(fn ($s) => $s->searchType->value === 'scan')
            ->pluck('searchId');

        $hitSearchIds = collect($properties->searches)
            ->filter(fn ($s) => $s->searchType->value === 'hit')
            ->pluck('searchId');

        $hotlistSearchIds = collect($properties->searches)
            ->filter(fn ($s) => $s->searchType->value === 'hotlist')
            ->pluck('searchId');

        $scanIds = $this->cappedResultIds($properties, $scanSearchIds);
        $hitIds = $this->cappedResultIds($properties, $hitSearchIds);
        $hotlistIds = $this->cappedResultIds($properties, $hotlistSearchIds);

        $scanSearchIdsByResultId = collect($properties->searchResults)
            ->filter(fn ($r) => $scanSearchIds->contains($r->searchId))
            ->groupBy('resultId')
            ->map(fn ($results) => $results->pluck('searchId')->unique()->values()->all());

        $hitSearchIdsByResultId = collect($properties->searchResults)
            ->filter(fn ($r) => $hitSearchIds->contains($r->searchId))
            ->groupBy('resultId')
            ->map(fn ($results) => $results->pluck('searchId')->unique()->values()->all());

        $hotlistSearchIdsByResultId = collect($properties->searchResults)
            ->filter(fn ($r) => $hotlistSearchIds->contains($r->searchId))
            ->groupBy('resultId')
            ->map(fn ($results) => $results->pluck('searchId')->unique()->values()->all());

        $scans = [];
        if ($scanIds !== [] && $user->can(PermissionEnum::ViewAny_SCAN->value)) {
            $rows = Scan::query()
                ->forOrg((int) $user->org_id)
                ->whereIn('id', $scanIds)
                ->limit(count($scanIds))
                ->get();

            $kitLabels = $this->kitCaptureLabels(
                $rows->pluck('kit_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
            );

            foreach ($rows as $scan) {
                $guid = $scan->guid ?? null;
                $capture = $kitLabels[(int) ($scan->kit_id ?? 0)] ?? null;
                $scans[] = [
                    'id' => (int) $scan->id,
                    'type' => 'scan',
                    'searchIds' => $scanSearchIdsByResultId[(int) $scan->id] ?? [],
                    'plate' => $scan->ocr ?? $scan->plate ?? '',
                    'scanned_at' => $this->formatScannedAt($scan->scanned_at, $user->timezone),
                    'lat' => $scan->lat,
                    'lng' => $scan->lng,
                    'kit_id' => $scan->kit_id ?? null,
                    'camera' => $capture['label'] ?? $scan->kit_name ?? $scan->camera_name ?? null,
                    'driver_name' => $capture['driver_name'] ?? null,
                    'kit_name' => $capture['kit_name'] ?? $scan->kit_name ?? null,
                    'guid' => $guid,
                    'state' => $scan->us_state ?? null,
                    'car_image' => scan_img($guid, VehicleImageTypeEnum::Car->value),
                    'plate_image' => scan_img($guid, VehicleImageTypeEnum::Plate->value),
                ];
            }
        }

        $hits = [];
        if ($hitIds !== [] && $user->can(PermissionEnum::ViewAny_Hit->value)) {
            $query = HitsListQuery::baseQuery($user, $this->hitWindowStart($properties, $hitSearchIds, $user->timezone));

            if ($query->getModel() instanceof ClickhouseModel) {
                $query->whereIn('id', $hitIds);
            } else {
                $query->whereIntegerInRaw('hits.id', $hitIds);
            }

            $rows = $query->limit(count($hitIds))->get();

            $kitLabels = $this->kitCaptureLabels(
                collect($rows)->pluck('kit_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
            );

            foreach ($rows as $hit) {
                $guid = $hit->scans_guid ?? $hit->scan_guid ?? null;
                $vehicle = trim(html_entity_decode(implode(' ', array_filter([
                    $hit->hotlist_vehicle_year ?? null,
                    $hit->hotlist_vehicle_make ?? null,
                    $hit->hotlist_vehicle_model ?? null,
                ]))));
                $capture = $kitLabels[(int) ($hit->kit_id ?? 0)] ?? null;

                $hits[] = [
                    'id' => (int) $hit->id,
                    'type' => 'hit',
                    'searchIds' => $hitSearchIdsByResultId[(int) $hit->id] ?? [],
                    'plate' => $hit->scans_ocr ?? $hit->ocr ?? $hit->plate ?? '',
                    'scanned_at' => $this->formatScannedAt($hit->scans_scanned_at ?? $hit->scanned_at ?? null, $user->timezone),
                    'lat' => $hit->lat,
                    'lng' => $hit->lng,
                    'camera' => $capture['label'] ?? $hit->kit_name ?? $hit->scans_camera_name ?? $hit->camera_name ?? null,
                    'driver_name' => $capture['driver_name'] ?? null,
                    'kit_name' => $capture['kit_name'] ?? $hit->kit_name ?? null,
                    'guid' => $guid,
                    'state' => $hit->scans_us_state ?? $hit->scan_us_state ?? $hit->hotlist_us_state ?? null,
                    'car_image' => scan_img($guid, VehicleImageTypeEnum::Car->value),
                    'plate_image' => scan_img($guid, VehicleImageTypeEnum::Plate->value),
                    'hotlist_name' => $hit->client_org_name ?? $hit->hotlist_name ?? null,
                    'case_number' => $hit->hotlist_remote_order_id ?? $hit->remote_order_id ?? null,
                    'hotlist_description' => $vehicle !== '' ? $vehicle : null,
                    'vehicle_make' => $hit->hotlist_vehicle_make ?? null,
                    'vehicle_model' => $hit->hotlist_vehicle_model ?? null,
                ];
            }
        }

        $hotlists = [];
        if ($hotlistIds !== [] && $user->can(PermissionEnum::ViewAny_Hotlist->value)) {
            $stagingOrgIds = ($user->org?->stagingOrgIds() ?? collect())
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            $rows = ClickhouseHotlist::query()
                ->visibleToOrg((int) $user->org_id, $stagingOrgIds)
                ->whereIn('id', $hotlistIds)
                ->limit(count($hotlistIds))
                ->get();

            foreach ($rows as $hotlist) {
                $hotlists[] = [
                    'id' => (int) $hotlist->id,
                    'type' => 'hotlist',
                    'searchIds' => $hotlistSearchIdsByResultId[(int) $hotlist->id] ?? [],
                    'plate' => $hotlist->plate ?? '',
                    'state' => $hotlist->us_state ?? '',
                    'vin' => $hotlist->vin ?? '',
                    'vehicle' => $hotlist->vehicle,
                    'vehicle_year' => $hotlist->vehicle_year,
                    'vehicle_make' => $hotlist->vehicle_make,
                    'vehicle_model' => $hotlist->vehicle_model,
                    'status' => $hotlist->status,
                    'status_label' => HotlistStatusEnum::tryFrom((int) $hotlist->status)?->name ?? (string) $hotlist->status,
                    'client' => $hotlist->client_org_name ?? '',
                    'lienholder' => $hotlist->lienholder_org_name ?? '',
                    'order_id' => $hotlist->remote_order_id ?? '',
                    'order_date' => $this->formatOptionalDate($hotlist->order_date),
                    'source' => ((int) $hotlist->is_staged === 1) ? 'Staging' : 'Direct',
                    'is_staged' => (bool) $hotlist->is_staged,
                ];
            }
        }

        return response()->json([
            'searches' => $searches,
            'scans' => $scans,
            'hits' => $hits,
            'hotlists' => $hotlists,
            'drivers' => $this->driverMarkers($properties),
            'zones' => $this->zoneMarkers($properties, $user),
        ]);
    }

    /**
     * At most SEARCH_LIMIT ids per search — never a warehouse total, and never
     * the legacy hydrate cap of 200.
     *
     * @param  Collection<int, string>  $searchIds
     * @return list<int>
     */
    private function cappedResultIds(AgentInvestigationProperties $properties, Collection $searchIds): array
    {
        if ($searchIds->isEmpty()) {
            return [];
        }

        return collect($properties->searchResults)
            ->filter(fn ($result) => $searchIds->contains($result->searchId))
            ->groupBy(fn ($result) => $result->searchId)
            ->flatMap(fn ($results) => $results
                ->pluck('resultId')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->take(LimitedToolResultMessage::SEARCH_LIMIT)
                ->values())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Earliest UTC window start across the hit searches being hydrated.
     *
     * HitsListQuery reads `clarity.hits_hot` for any window inside 90 days, and
     * that table has a 90-day TTL. Hydrating with now() would therefore pin every
     * lookup to the hot table and return nothing for ids a search legitimately
     * found in `hits_cold` — the chat would report N hits while the table and map
     * showed none. Null (an open-ended search) falls through to the cold table,
     * which holds all history.
     *
     * @param  Collection<int, string>  $hitSearchIds
     */
    private function hitWindowStart(AgentInvestigationProperties $properties, Collection $hitSearchIds, ?string $timezone): ?string
    {
        $earliest = null;

        foreach ($properties->searches as $search) {
            if (! $hitSearchIds->contains($search->searchId)) {
                continue;
            }

            $start = $search->parameters['start_date'] ?? null;

            if (! is_string($start) || $start === '') {
                return null;
            }

            try {
                $startUtc = convertToUtc($start, filled($timezone) ? $timezone : 'UTC');
            } catch (InvalidFormatException) {
                return null;
            }

            if ($earliest === null || $startUtc->lessThan($earliest)) {
                $earliest = $startUtc;
            }
        }

        return $earliest?->toDateTimeString();
    }

    /**
     * @return list<array{
     *     id: int,
     *     type: string,
     *     searchIds: list<string>,
     *     plate: string,
     *     last_seen_at_utc: ?string,
     *     minutes_ago: ?int,
     *     lat: float,
     *     lng: float,
     *     camera: ?string,
     *     place: ?string,
     *     driver_name: ?string,
     *     kit_name: ?string
     * }>
     */
    private function driverMarkers(AgentInvestigationProperties $properties): array
    {
        $drivers = [];

        foreach ($properties->searches as $search) {
            if ($search->searchType !== SearchType::ScoutDriver) {
                continue;
            }

            $positions = $search->parameters['positions'] ?? [];
            if (! is_array($positions)) {
                continue;
            }

            foreach ($positions as $position) {
                if (! is_array($position)) {
                    continue;
                }

                $kitId = (int) ($position['kit_id'] ?? 0);
                $lat = (float) ($position['lat'] ?? 0);
                $lng = (float) ($position['lng'] ?? 0);
                if ($kitId < 1 || ($lat === 0.0 && $lng === 0.0)) {
                    continue;
                }

                $driverName = isset($position['driver_name']) ? (string) $position['driver_name'] : '';
                $kitName = isset($position['name']) ? (string) $position['name'] : '';
                $lastSeenAt = $this->lastSeenAtUtc($position);

                $drivers[] = [
                    'id' => $kitId,
                    'type' => SearchType::ScoutDriver->value,
                    'searchIds' => [$search->searchId],
                    'plate' => $driverName !== '' ? $driverName : ($kitName !== '' ? $kitName : 'Kit '.$kitId),
                    'last_seen_at_utc' => $lastSeenAt,
                    'minutes_ago' => $this->minutesAgoFrom($lastSeenAt),
                    'lat' => $lat,
                    'lng' => $lng,
                    'camera' => $kitName !== '' ? $kitName : null,
                    'place' => isset($position['place']) && is_string($position['place']) ? $position['place'] : null,
                    'driver_name' => $driverName !== '' ? $driverName : null,
                    'kit_name' => $kitName !== '' ? $kitName : null,
                ];
            }
        }

        return $drivers;
    }

    /**
     * @return list<array{
     *     id: int,
     *     type: string,
     *     searchIds: list<string>,
     *     name: string,
     *     plate: string,
     *     is_no_scan_zone: bool,
     *     assigned_drivers: list<string>,
     *     zip_count: int,
     *     color: string,
     *     lat: ?float,
     *     lng: ?float,
     *     geometry: array{type: string, coordinates: mixed}|null,
     *     editor_url: string
     * }>
     */
    private function zoneMarkers(AgentInvestigationProperties $properties, User $user): array
    {
        $zoneSearchIds = collect($properties->searches)
            ->filter(fn ($search) => $search->searchType === SearchType::Zone)
            ->pluck('searchId');

        $zoneIds = $this->cappedResultIds($properties, $zoneSearchIds);

        if ($zoneIds === [] || ! OttoScoutAccess::adminCanUseScout($user) || ! $user->can('viewAny', Zone::class)) {
            return [];
        }

        $searchIdsByResultId = collect($properties->searchResults)
            ->filter(fn ($result) => $zoneSearchIds->contains($result->searchId))
            ->groupBy('resultId')
            ->map(fn ($results) => $results->pluck('searchId')->unique()->values()->all());

        $rows = Zone::query()
            ->forOrg((int) $user->org_id)
            ->with('users')
            ->withGeoJson()
            ->whereIn('id', $zoneIds)
            ->get()
            ->keyBy('id');

        $zones = [];

        foreach ($zoneIds as $zoneId) {
            $zone = $rows->get($zoneId) ?? $rows->get((string) $zoneId);

            if (! $zone) {
                continue;
            }
            [$lat, $lng] = $this->centroidLatLng($zone->centroid_geojson ?? null);

            $zones[] = [
                'id' => (int) $zone->id,
                'type' => SearchType::Zone->value,
                'searchIds' => $searchIdsByResultId[(int) $zone->id] ?? [],
                'name' => (string) $zone->name,
                'plate' => (string) $zone->name,
                'is_no_scan_zone' => (bool) $zone->is_no_scan_zone,
                'assigned_drivers' => $zone->users
                    ->pluck('name')
                    ->filter()
                    ->map(fn ($name): string => (string) $name)
                    ->values()
                    ->all(),
                'zip_count' => count($zone->definition['zip_codes'] ?? []),
                'color' => $zone->is_no_scan_zone ? Zone::NO_SCAN_COLOR : (string) $zone->color,
                'lat' => $lat,
                'lng' => $lng,
                'geometry' => $this->decodeGeometry($zone->geom_geojson ?? null),
                'editor_url' => ManageZones::getUrl(['zone' => $zone->id]),
            ];
        }

        return $zones;
    }

    /**
     * Compiled PostGIS geometry as GeoJSON — never the raw `geom` bytea.
     *
     * @return array{type: string, coordinates: mixed}|null
     */
    private function decodeGeometry(mixed $geojson): ?array
    {
        if (! is_string($geojson) || $geojson === '') {
            return null;
        }

        $decoded = json_decode($geojson, true);

        if (! is_array($decoded)) {
            return null;
        }

        $type = $decoded['type'] ?? '';

        if (! in_array($type, ['Polygon', 'MultiPolygon'], true) || ! isset($decoded['coordinates'])) {
            return null;
        }

        return [
            'type' => $type,
            'coordinates' => $decoded['coordinates'],
        ];
    }

    /**
     * @return array{0: ?float, 1: ?float}
     */
    private function centroidLatLng(mixed $geojson): array
    {
        if (! is_string($geojson) || $geojson === '') {
            return [null, null];
        }

        $decoded = json_decode($geojson, true);

        if (! is_array($decoded) || ($decoded['type'] ?? '') !== 'Point') {
            return [null, null];
        }

        $coords = $decoded['coordinates'] ?? null;

        if (! is_array($coords) || count($coords) < 2) {
            return [null, null];
        }

        $lng = (float) $coords[0];
        $lat = (float) $coords[1];

        if ($lat === 0.0 && $lng === 0.0) {
            return [null, null];
        }

        return [$lat, $lng];
    }

    /**
     * @param  list<int>  $kitIds
     * @return array<int, array{kit_name: ?string, driver_name: ?string, label: ?string}>
     */
    private function kitCaptureLabels(array $kitIds): array
    {
        if ($kitIds === []) {
            return [];
        }

        $kits = Kit::query()
            ->whereIn('id', $kitIds)
            ->get(['id', 'name']);

        $settings = KitSettings::query()
            ->whereIn('kit_id', $kitIds)
            ->with('user:id,name')
            ->get()
            ->keyBy('kit_id');

        $labels = [];

        foreach ($kits as $kit) {
            $driverName = $settings->get($kit->id)?->user?->name;
            $kitName = (string) $kit->name;

            $labels[(int) $kit->id] = [
                'kit_name' => $kitName !== '' ? $kitName : null,
                'driver_name' => is_string($driverName) && $driverName !== '' ? $driverName : null,
                'label' => $this->formatCaptureLabel(
                    is_string($driverName) ? $driverName : null,
                    $kitName,
                ),
            ];
        }

        return $labels;
    }

    private function formatCaptureLabel(?string $driverName, ?string $kitName): ?string
    {
        $driverName = trim((string) $driverName);
        $kitName = trim((string) $kitName);

        if ($driverName !== '' && $kitName !== '') {
            return "{$driverName} ({$kitName})";
        }

        if ($driverName !== '') {
            return $driverName;
        }

        return $kitName !== '' ? $kitName : null;
    }

    /**
     * @param  array<string, mixed>  $position
     */
    private function lastSeenAtUtc(array $position): ?string
    {
        foreach (['last_seen_at_utc', 'scanned_at'] as $key) {
            if (! isset($position[$key])) {
                continue;
            }

            $iso = $this->toIso8601($position[$key]);
            if ($iso !== null) {
                return $iso;
            }
        }

        return null;
    }

    private function minutesAgoFrom(?string $lastSeenAtIso): ?int
    {
        if ($lastSeenAtIso === null || $lastSeenAtIso === '') {
            return null;
        }

        try {
            return (int) abs(now()->diffInMinutes(Carbon::parse($lastSeenAtIso)));
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private function formatScannedAt(mixed $value, ?string $timezone): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timezone = filled($timezone) ? (string) $timezone : 'UTC';

        try {
            $timestamp = $value instanceof CarbonInterface
                ? $value->copy()
                : Carbon::parse((string) $value, 'UTC');
        } catch (InvalidFormatException) {
            return null;
        }

        $name = $timestamp->getTimezone()->getName();
        if (in_array($name, ['UTC', 'Z', '+00:00', 'GMT'], true) && $timezone !== 'UTC') {
            $timestamp = $timestamp->setTimezone($timezone);
        }

        return $timestamp->format('M d, Y h:i A T');
    }

    private function toIso8601(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (InvalidFormatException) {
            return null;
        }
    }

    protected function formatOptionalDate(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
