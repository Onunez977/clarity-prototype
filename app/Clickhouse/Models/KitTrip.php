<?php

declare(strict_types=1);

namespace App\Clickhouse\Models;

use App\Clickhouse\Scopes\ApplyFinal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Per-kit driving trips (the ReplacingMergeTree `kit_trips` table). Always
 * queried with FINAL to collapse ReplacingMergeTree versions — applied globally
 * here so callers don't hand-roll `DB::clickhouse('kit_trips', final: true)`.
 * The table has no `_peerdb_is_deleted` column (it is refresh-built, not CDC),
 * so it does NOT use FiltersCdcDeletes.
 */
class KitTrip extends ClickhouseModel
{
    protected $table = 'kit_trips';

    protected static function booted(): void
    {
        static::addGlobalScope(new ApplyFinal);
    }

    public function scopeForOrg(Builder $query, int $orgId): Builder
    {
        return $query->where('org_id', $orgId);
    }

    public function scopeForKit(Builder $query, ?int $kitId): Builder
    {
        return $query->when($kitId, fn (Builder $q) => $q->where('kit_id', $kitId));
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->where('trip_date', $date);
    }

    public function scopeBetweenDates(Builder $query, string $start, string $end): Builder
    {
        return $query->whereBetween('trip_date', [$start, $end]);
    }

    public function scopeBefore(Builder $query, string $date): Builder
    {
        return $query->where('trip_date', '<', $date);
    }

    /**
     * Decode the `path_simplified` LineString into [lng, lat] pairs selected as
     * `coordinates` — the shape every Scout trip surface consumes (and parses
     * via ClarityScoutTripsController::parseCoordinates / calculateMiles).
     */
    public function scopeWithCoordinates(Builder $query): Builder
    {
        return $query->selectRaw('arrayMap(p -> [tupleElement(p, 1), tupleElement(p, 2)], path_simplified) AS coordinates');
    }
}
