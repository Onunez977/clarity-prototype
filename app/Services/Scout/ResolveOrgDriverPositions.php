<?php

declare(strict_types=1);

namespace App\Services\Scout;

use App\Clickhouse\Queries\OrgLivePositionsQuery;
use App\Clickhouse\Queries\OrgScanPathPositionsQuery;
use App\Models\Kit;
use App\Models\KitSettings;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Org-scoped Scout live positions shared by Bird's Eye and Otto (DEV-2321).
 *
 * Reuses the production Bird's Eye pipeline: org kits via {@see Kit::forOrg()}
 * + separate {@see KitSettings} attach, then tier-1 {@see OrgLivePositionsQuery}
 * degrading to tier-2 {@see OrgScanPathPositionsQuery} within a 24h UTC window.
 */
class ResolveOrgDriverPositions
{
    /** Live dot is meaningless once stale; mirror kitPosition's 24h window. */
    public const LIVE_CUTOFF_HOURS = 24;

    /**
     * All of the org's non-deleted kits, with their kit_settings attached where
     * present for labelling. Kits need no kit_settings row and no user_id to
     * appear — enrollment no longer gates map visibility.
     *
     * Kits live on the `zod` connection while kit_settings lives on `platform`,
     * so a whereHas()/join across the two resolves to nothing (cross-connection
     * correlated subquery). Settings are therefore resolved with a separate,
     * keyed lookup — the relation is pre-set so labelling stays a single query,
     * no N+1, and is null when a kit has no settings row.
     *
     * Cached per org for a short window rather than event-busted: kit existence
     * and the kit_metadata soft-delete flag are written to `zod` outside this
     * app (CDC-fed). The TTL drops to 3s under admin impersonation and is 10s
     * otherwise.
     *
     * @return EloquentCollection<int, Kit>
     */
    public function orgKits(int $orgId): EloquentCollection
    {
        $ttl = (session()?->has('admin_impersonator') ?? false)
            ? now()->addSeconds(3)
            : now()->addSeconds(10);

        return Cache::remember(
            'scanner:birds-eye-org-kits:'.$orgId,
            $ttl,
            function () use ($orgId): EloquentCollection {
                $kits = Kit::query()
                    ->forOrg($orgId)
                    ->excludingDeleted()
                    ->orderBy('name')
                    ->get();

                if ($kits->isEmpty()) {
                    return $kits;
                }

                $settings = KitSettings::query()
                    ->whereIn('kit_id', $kits->pluck('id')->all())
                    ->with('user')
                    ->get()
                    ->keyBy('kit_id');

                return $kits
                    ->each(static fn (Kit $kit) => $kit->setRelation('settings', $settings->get($kit->id)))
                    ->values();
            }
        );
    }

    /**
     * The kits in scope for this org: the org's Scout kits, optionally narrowed
     * by kit IDs that are ALWAYS intersected against the org's own kits (callers
     * can never widen past the org).
     *
     * @param  list<int>|null  $kitIds
     * @return EloquentCollection<int, Kit>
     */
    public function forOrg(int $orgId, ?array $kitIds = null): EloquentCollection
    {
        $orgKits = $this->orgKits($orgId);

        if ($kitIds === null || $kitIds === []) {
            return $orgKits;
        }

        $requestedIds = array_map('intval', $kitIds);

        return $orgKits->filter(static fn (Kit $kit) => in_array((int) $kit->id, $requestedIds, true))->values();
    }

    /**
     * Latest live position for each in-scope kit: primary GPS fix
     * ({@see OrgLivePositionsQuery}, keyed by lower(kit_mapping)) degrading to
     * the last scan-derived point ({@see OrgScanPathPositionsQuery}, keyed by
     * kit_id) for kits that scan but do not report GPS within the window
     * (DEV-2253). Tier 1 always wins when both exist; tier 2 never carries a
     * heading (kit_scan_paths has no bearing column).
     *
     * @param  EloquentCollection<int, Kit>  $kits
     * @return list<array{kit_id: int, name: string|null, driver_name: string|null, lat: float,
     *     lng: float, heading: float|null, scanned_at: string, minutes_ago: int,
     *     vehicle_type: string|null, source: string, position_source: string}>
     */
    public function positions(int $orgId, EloquentCollection $kits): array
    {
        if ($kits->isEmpty()) {
            return [];
        }

        $byMapping = $this->kitsByMapping($kits);
        // One UTC cutoff for both tiers so they cannot diverge (DEV-2164).
        $cutoff = now()->utc()->subHours(self::LIVE_CUTOFF_HOURS)->toDateTimeString();

        $rows = [];

        if ($byMapping !== []) {
            try {
                $rows = OrgLivePositionsQuery::query([
                    'mappings' => array_keys($byMapping),
                    'cutoff' => $cutoff,
                ]);
            } catch (\Throwable $e) {
                Log::warning('birds-eye live positions read failed; returning empty set', [
                    'org_id' => $orgId,
                    'exception' => $e->getMessage(),
                ]);

                return [];
            }
        }

        $data = [];
        $resolved = [];

        foreach ($rows as $row) {
            $kit = $byMapping[$row['mapping']] ?? null;

            if ($kit === null || ($row['lat'] === 0.0 && $row['lng'] === 0.0)) {
                continue;
            }

            $resolved[(int) $kit->id] = true;

            $data[] = $this->driverPositionEntry(
                $kit,
                (float) $row['lat'],
                (float) $row['lng'],
                Carbon::parse($row['timestamp_utc'], 'UTC'),
                $row['speed'] > 1 ? (float) $row['heading'] : null,
                'gps',
            );
        }

        // From the full collection, not $byMapping: kitsByMapping() drops kits with
        // no kit_mapping, but kit_scan_paths is keyed by kit_id so they're rescuable.
        $missing = $kits->reject(static fn (Kit $kit) => isset($resolved[(int) $kit->id]))->values();

        if ($missing->isNotEmpty()) {
            try {
                $fallback = OrgScanPathPositionsQuery::query([
                    'org_id' => $orgId,
                    'kit_ids' => $missing->pluck('id')->map(static fn ($id) => (int) $id)->all(),
                    'cutoff' => $cutoff,
                ]);

                $byId = $missing->keyBy(static fn (Kit $kit) => (int) $kit->id);

                foreach ($fallback as $row) {
                    $kit = $byId->get((int) $row['kit_id']);

                    if ($kit === null) {
                        continue;
                    }

                    $data[] = $this->driverPositionEntry(
                        $kit,
                        (float) $row['lat'],
                        (float) $row['lng'],
                        Carbon::parse($row['timestamp_utc'], 'UTC'),
                        null,
                        'scan_path',
                    );
                }
            } catch (\Throwable $e) {
                // Degrade to tier-1-only; a fallback failure must never wipe the
                // drivers that ARE reporting GPS.
                Log::warning('birds-eye scan-path fallback read failed; returning live positions only', [
                    'org_id' => $orgId,
                    'missing_kits' => $missing->count(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $data;
    }

    /**
     * Map lowercased kit_mapping => Kit for the in-scope kits (mappings are the
     * only key sc_positions carries). Kits without a mapping are skipped.
     *
     * @param  EloquentCollection<int, Kit>  $kits
     * @return array<string, Kit>
     */
    public function kitsByMapping(EloquentCollection $kits): array
    {
        $byMapping = [];

        foreach ($kits as $kit) {
            $mapping = $kit->kit_mapping;

            if ($mapping === null || $mapping === '') {
                continue;
            }

            $byMapping[strtolower($mapping)] = $kit;
        }

        return $byMapping;
    }

    /**
     * @return array{kit_id: int, name: string|null, driver_name: string|null, lat: float,
     *     lng: float, heading: float|null, scanned_at: string, minutes_ago: int,
     *     vehicle_type: string|null, source: string, position_source: string}
     */
    private function driverPositionEntry(Kit $kit, float $lat, float $lng, Carbon $scannedAt, ?float $heading, string $positionSource): array
    {
        return [
            'kit_id' => (int) $kit->id,
            'name' => $kit->name,
            'driver_name' => $kit->settings?->user?->name,
            'lat' => $lat,
            'lng' => $lng,
            'heading' => $heading,
            'scanned_at' => $scannedAt->toIso8601String(),
            'minutes_ago' => (int) abs(now()->diffInMinutes($scannedAt)),
            'vehicle_type' => $kit->settings?->vehicle_type?->value,
            // Scanner-hardware vs browser-GPS (consumed by stores/map.js) — do
            // not repurpose this for provenance.
            'source' => 'scan',
            // DEV-2253: 'gps' = a live sc_positions fix, 'scan_path' = a
            // kit_scan_paths rescue.
            'position_source' => $positionSource,
        ];
    }
}
