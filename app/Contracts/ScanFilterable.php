<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface ScanFilterable
{
    /**
     * @param  bool  $flexSearch  Uses get_license_plate_variants() UDF when enabled (PG and ClickHouse).
     */
    public function scopeForPlate(Builder $query, string $plate, bool $flexSearch = false): Builder;

    public function scopeForDisposition(Builder $query, string $disposition): Builder;

    /**
     * Exclude scans with the given disposition (include NULLs).
     * ClickHouse uses whereRaw to avoid the nested-closure binding bug.
     */
    public function scopeExcludeDisposition(Builder $query, string $disposition): Builder;

    /**
     * @param  string|null  $start  Nullable; skipped if null.
     * @param  string|null  $end  Nullable; skipped if null.
     * @param  string|null  $timezone  Defaults to auth user's timezone.
     */
    public function scopeBetween(Builder $query, $start = null, $end = null, ?string $timezone = null): Builder;
}
