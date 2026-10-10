<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Support\Scout\Geohash;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;

/**
 * @property int $id
 * @property int $org_id
 * @property string $name
 * @property string|null $description
 * @property string $color
 * @property bool $is_no_scan_zone
 * @property array<string, mixed> $definition
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string|null $geom_geojson
 * @property-read string|null $centroid_geojson
 * @property-read Org $org
 * @property-read User|null $creator
 * @property-read Collection<int, User> $users
 *
 * @mixin \Eloquent
 */
class Zone extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $connection = 'platform';

    protected $table = 'zones';

    /**
     * Color applied to zones flagged as `is_no_scan_zone`. Renderers (Filament
     * table swatch, scanner map, etc.) read `color` directly, so persisting
     * gray here makes the visualization fall out of the existing pipeline.
     */
    public const NO_SCAN_COLOR = '#9ca3af';

    /**
     * Constrained zone palette shared by the map editor and the Zones list —
     * a fixed set so operators can't pick colors that fight with or hide scan
     * tracks. Final colors land once the map-layer / gridding-line palette is
     * settled (DEV-1960).
     *
     * @var array<string, string>
     */
    public const PRESET_COLORS = [
        '#2563eb' => 'Blue',
        '#0d9488' => 'Teal',
        '#ea580c' => 'Orange',
        '#9333ea' => 'Purple',
        '#16a34a' => 'Green',
    ];

    public const DEFAULT_COLOR = '#2563eb';

    /**
     * Snap a stored color to the preset palette, falling back to the default for
     * legacy rows whose free-form color predates the constrained picker.
     */
    public static function coerceColor(?string $color): string
    {
        return isset(self::PRESET_COLORS[$color]) ? $color : self::DEFAULT_COLOR;
    }

    /**
     * Resolve a hex code or palette name (e.g. "blue") to a preset color.
     */
    public static function colorFromHint(?string $hint): string
    {
        $hint = strtolower(trim((string) $hint));

        if ($hint === '') {
            return self::DEFAULT_COLOR;
        }

        if (isset(self::PRESET_COLORS[$hint])) {
            return $hint;
        }

        foreach (self::PRESET_COLORS as $hex => $name) {
            if (strtolower($name) === $hint) {
                return $hex;
            }
        }

        return self::coerceColor($hint);
    }

    protected $fillable = [
        'org_id',
        'name',
        'description',
        'color',
        'is_no_scan_zone',
        'definition',
        'created_by_user_id',
    ];

    protected $casts = [
        'definition' => 'array',
        'is_no_scan_zone' => 'boolean',
    ];

    /**
     * Always exclude the raw geometry column from default selects — it can be
     * large and we never want to serialize the binary form. Use the
     * `withGeoJson` scope to opt into reading geometry as GeoJSON.
     */
    protected $hidden = [
        'geom',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('zone')
            ->logOnly(['name', 'description', 'color', 'is_no_scan_zone', 'definition'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'zone_user')->withTimestamps();
    }

    public function scopeForOrg(Builder $query, int $orgId): Builder
    {
        return $query->where('org_id', $orgId);
    }

    public function scopeWithGeoJson(Builder $query): Builder
    {
        // Schema-qualify PostGIS calls — the platform connection's search_path is `platform`, not `public`.
        return $query->addSelect([
            'zones.*',
            DB::raw('public.ST_AsGeoJSON(geom) AS geom_geojson'),
            DB::raw('public.ST_AsGeoJSON(public.ST_Centroid(geom)) AS centroid_geojson'),
        ]);
    }

    /**
     * Expose the geometry's bounding box as four attributes
     * (`bbox_min_lng`/`bbox_min_lat`/`bbox_max_lng`/`bbox_max_lat`). Used by the
     * zone-coverage builder to tessellate the polygon into a geohash grid.
     */
    public function scopeWithBbox(Builder $query): Builder
    {
        return $query->addSelect([
            'zones.id',
            DB::raw('public.ST_XMin(geom) AS bbox_min_lng'),
            DB::raw('public.ST_YMin(geom) AS bbox_min_lat'),
            DB::raw('public.ST_XMax(geom) AS bbox_max_lng'),
            DB::raw('public.ST_YMax(geom) AS bbox_max_lat'),
        ]);
    }

    /**
     * Of the given geohashes, return those whose cell rectangle intersects the
     * union of the given zones' geometry — one PostGIS round-trip. Shared by the
     * coverage tessellation and the zone-clipped honey-hole heatmaps. Geohashes
     * are validated against the base32 alphabet before being inlined.
     *
     * @param  array<int, string>  $geohashes
     * @param  array<int, int>  $zoneIds
     * @return array<int, string>
     */
    public static function intersectingGeohashes(array $geohashes, array $zoneIds, int $orgId): array
    {
        $zoneIds = array_values(array_map('intval', $zoneIds));

        if ($geohashes === [] || $zoneIds === []) {
            return [];
        }

        // Cache the ST_Union/ST_Intersects clip per org + zone set + geohash set.
        // F1's snapped bbox stabilizes the geohash set per viewport, so close
        // pans share this entry instead of re-paying the PostGIS clip each fetch.
        // Zone-geometry edits go stale up to the TTL (acceptable — zones change rarely).
        $sortedZoneIds = $zoneIds;
        sort($sortedZoneIds);
        $sortedGeohashes = $geohashes;
        sort($sortedGeohashes);
        $key = 'scanner:zone-gh:'.$orgId.':'.implode('-', $sortedZoneIds).':'.md5(implode(',', $sortedGeohashes));

        return Cache::remember($key, now()->addMinutes(5), static function () use ($geohashes, $zoneIds, $orgId) {
            $hashes = $minLng = $minLat = $maxLng = $maxLat = [];

            foreach ($geohashes as $geohash) {
                if (! preg_match('/^[0-9b-hjkmnp-z]+$/', $geohash)) {
                    continue;
                }

                $b = Geohash::decodeBounds($geohash);

                if ($b === null) {
                    continue;
                }

                $hashes[] = "'".$geohash."'";
                $minLng[] = sprintf('%.10f', $b['min_lng']);
                $minLat[] = sprintf('%.10f', $b['min_lat']);
                $maxLng[] = sprintf('%.10f', $b['max_lng']);
                $maxLat[] = sprintf('%.10f', $b['max_lat']);
            }

            if ($hashes === []) {
                return [];
            }

            $idList = implode(',', $zoneIds);
            $ghArr = 'ARRAY['.implode(',', $hashes).']::text[]';
            $minLngArr = 'ARRAY['.implode(',', $minLng).']::float8[]';
            $minLatArr = 'ARRAY['.implode(',', $minLat).']::float8[]';
            $maxLngArr = 'ARRAY['.implode(',', $maxLng).']::float8[]';
            $maxLatArr = 'ARRAY['.implode(',', $maxLat).']::float8[]';

            $rows = DB::connection('platform')->select(
                "WITH zone_geom AS (
                     SELECT public.ST_Union(geom) AS g
                     FROM zones
                     WHERE id IN ({$idList}) AND org_id = ? AND geom IS NOT NULL
                 )
                 SELECT v.gh AS gh
                 FROM unnest({$ghArr}, {$minLngArr}, {$minLatArr}, {$maxLngArr}, {$maxLatArr})
                      AS v(gh, minlng, minlat, maxlng, maxlat)
                 CROSS JOIN zone_geom
                 WHERE public.ST_Intersects(
                     zone_geom.g,
                     public.ST_MakeEnvelope(v.minlng, v.minlat, v.maxlng, v.maxlat, 4326)
                 )",
                [$orgId]
            );

            return array_map(static fn ($row) => $row->gh, $rows);
        });
    }

    /**
     * Rebuild `geom` from the structured `definition` payload:
     *   { polygons: [GeoJSON Polygon|MultiPolygon, ...], zip_codes: ["75201", ...] }
     *
     * Caller is responsible for persisting `definition` before invoking this.
     */
    public function rebuildGeometryFromDefinition(): void
    {
        $polygons = $this->definition['polygons'] ?? [];
        $zipCodes = $this->definition['zip_codes'] ?? [];

        $pieces = [];
        $bindings = [];

        foreach ($polygons as $geojson) {
            $decoded = is_string($geojson) ? json_decode($geojson, true) : $geojson;

            // Skip degenerate shapes — a ring needs ≥4 positions (3 distinct + close).
            // GEOS rejects anything less, which would 500 the entire save.
            if (! self::geoJsonHasValidRing($decoded)) {
                continue;
            }

            $pieces[] = 'public.ST_GeomFromGeoJSON(?)';
            $bindings[] = is_string($geojson) ? $geojson : json_encode($geojson);
        }

        if ($zipCodes !== []) {
            $placeholders = implode(',', array_fill(0, count($zipCodes), '?'));
            // Tiger zcta5_all geometries are in NAD83 (SRID 4269); reproject to 4326 to match zones.geom.
            $pieces[] = "(SELECT public.ST_Transform(public.ST_Union(the_geom), 4326) FROM tiger_data.zcta5_all WHERE zcta5ce IN ($placeholders))";
            foreach ($zipCodes as $zip) {
                $bindings[] = (string) $zip;
            }
        }

        if ($pieces === []) {
            DB::connection('platform')
                ->table('zones')
                ->where('id', $this->id)
                ->update(['geom' => null]);

            return;
        }

        $unionExpr = count($pieces) === 1 ? $pieces[0] : 'public.ST_Union(ARRAY['.implode(',', $pieces).'])';

        DB::connection('platform')->update(
            "UPDATE zones SET geom = public.ST_Multi(public.ST_CollectionExtract(public.ST_MakeValid($unionExpr), 3)) WHERE id = ?",
            [...$bindings, $this->id]
        );
    }

    /**
     * True when the GeoJSON geometry has at least one ring with ≥4 positions
     * (3 distinct vertices + a closing point) — the minimum GEOS will accept.
     * Guards `rebuildGeometryFromDefinition()` against degenerate point/line rings.
     */
    private static function geoJsonHasValidRing(mixed $geometry): bool
    {
        if (! is_array($geometry)) {
            return false;
        }

        $rings = match ($geometry['type'] ?? null) {
            'Polygon' => [$geometry['coordinates'][0] ?? null],
            'MultiPolygon' => array_map(fn ($poly) => $poly[0] ?? null, $geometry['coordinates'] ?? []),
            default => [],
        };

        foreach ($rings as $ring) {
            if (is_array($ring) && count($ring) >= 4) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lightweight zip autocomplete used by the zone map editor. Returns a list
     * of { zip, geojson } pairs scoped by query/state, with geometry projected
     * to WGS84 for direct rendering on Google Maps.
     */
    public static function searchZipCodes(string $query, int $limit = 25): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        // zcta5_all is partitioned by state; union per zcta5ce so cross-state ZCTAs match what rebuildGeometryFromDefinition() saves.
        return DB::connection('zod')
            ->table('tiger_data.zcta5_all')
            ->whereRaw('zcta5ce LIKE ?', [$query.'%'])
            ->groupBy('zcta5ce')
            ->orderBy('zcta5ce')
            ->limit($limit)
            ->get([
                'zcta5ce',
                DB::raw('ST_AsGeoJSON(ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_Transform(ST_Union(the_geom), 4326)), 3))) AS shape'),
            ])
            ->map(fn ($row) => [
                'zip' => $row->zcta5ce,
                'geojson' => $row->shape,
            ])
            ->all();
    }
}
