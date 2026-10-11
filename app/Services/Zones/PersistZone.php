<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Enums\ScoutAnalyticsEvent;
use App\Enums\ScoutAnalyticsPage;
use App\Facades\Analytics;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;

/**
 * Canonical zone write path shared by the map editor and Otto Accept.
 *
 * Callers authorize create/update before invoking. Geometry rebuild, the
 * unresolved-ZIP and empty-geometry refusal, no-scan gray, assignment sync,
 * driver broadcasts, and analytics stay on this one path.
 */
class PersistZone
{
    public function __construct(
        private ZoneAssignmentSyncer $assignments,
        private ZoneBroadcaster $broadcaster,
        private ZipBoundaryLookup $zipBoundaries,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     description?: ?string,
     *     color?: ?string,
     *     is_no_scan_zone?: bool,
     *     definition?: array{polygons?: list<mixed>, zip_codes?: list<string|int>},
     *     user_ids?: list<int>
     * }  $attributes
     */
    public function create(User $actor, array $attributes, ScoutAnalyticsPage $page): Zone
    {
        $zone = new Zone;
        $zone->org_id = (int) $actor->org_id;
        $zone->created_by_user_id = $actor->id;

        $this->persist($zone, $actor, $attributes, isCreate: true, page: $page);

        return $zone;
    }

    /**
     * @param  array{
     *     name: string,
     *     description?: ?string,
     *     color?: ?string,
     *     is_no_scan_zone?: bool,
     *     definition?: array{polygons?: list<mixed>, zip_codes?: list<string|int>},
     *     user_ids?: list<int>
     * }  $attributes
     */
    public function update(Zone $zone, User $actor, array $attributes, ScoutAnalyticsPage $page): Zone
    {
        $this->persist($zone, $actor, $attributes, isCreate: false, page: $page);

        return $zone;
    }

    /**
     * @param  array{
     *     name: string,
     *     description?: ?string,
     *     color?: ?string,
     *     is_no_scan_zone?: bool,
     *     definition?: array{polygons?: list<mixed>, zip_codes?: list<string|int>},
     *     user_ids?: list<int>
     * }  $attributes
     */
    private function persist(
        Zone $zone,
        User $actor,
        array $attributes,
        bool $isCreate,
        ScoutAnalyticsPage $page,
    ): void {
        $isNoScan = (bool) ($attributes['is_no_scan_zone'] ?? false);
        $definition = [
            'polygons' => array_values($attributes['definition']['polygons'] ?? []),
            'zip_codes' => array_values(array_unique(array_map(
                static fn (mixed $zip): string => (string) $zip,
                $attributes['definition']['zip_codes'] ?? [],
            ))),
        ];

        // ST_Union over ARRAY[...] drops NULL members, so a zip that resolves to nothing
        // still yields a non-empty geometry whenever a polygon is present. Checking
        // emptiness alone would silently drop that zip; resolve the zips separately.
        // Deliberately BEFORE the transaction: a swallowed query error inside an open
        // Postgres transaction poisons it, and the COMMIT would then come back as a
        // silent ROLLBACK under a "Zone saved" toast.
        $unresolved = $this->zipBoundaries->unresolved($definition['zip_codes']);

        DB::connection('platform')->transaction(function () use ($zone, $attributes, $isNoScan, $definition, $unresolved): void {
            $zone->fill([
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'color' => $isNoScan
                    ? Zone::NO_SCAN_COLOR
                    : Zone::coerceColor($attributes['color'] ?? null),
                'is_no_scan_zone' => $isNoScan,
                'definition' => $definition,
            ])->save();

            $zone->rebuildGeometryFromDefinition();

            $isEmpty = (int) DB::connection('platform')
                ->table('zones')
                ->where('id', $zone->id)
                ->rawValue('(geom IS NULL OR public.ST_IsEmpty(geom))::int AS is_empty');

            if ($isEmpty === 1 || $unresolved !== []) {
                throw new ZoneGeometryRejected($this->geometryFailureMessage($zone, $unresolved));
            }

            $affected = $this->assignments->syncZoneUsers(
                $zone,
                $attributes['user_ids'] ?? [],
                ZoneAssignmentSyncer::MODE_REPLACE,
            );

            $this->broadcaster->broadcastUpdated($zone, $affected);
        });

        Analytics::track(
            ($isCreate ? ScoutAnalyticsEvent::ZoneCreate : ScoutAnalyticsEvent::ZoneEdit)->value,
            $actor,
            ['action' => 'save'],
            $page->value,
        );
    }

    /**
     * @param  list<string>  $unresolved
     */
    private function geometryFailureMessage(Zone $zone, array $unresolved): string
    {
        if ($unresolved !== []) {
            $shown = array_slice($unresolved, 0, 10);
            $suffix = count($unresolved) > 10 ? ' and '.(count($unresolved) - 10).' more' : '';

            return 'No Census boundary found for ZIP code(s): '.implode(', ', $shown).$suffix
                .'. Remove them, or draw the zone manually.';
        }

        if (($zone->definition['polygons'] ?? []) === []) {
            return 'Those ZIP codes produced no mappable area. Remove them, or draw the zone manually.';
        }

        return 'The drawn zone has no area. Redraw the polygon or use a different ZIP code.';
    }
}
