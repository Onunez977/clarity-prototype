<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface MissedOpportunityFilterable
{
    public function scopeForVin(Builder $query, string $vin): Builder;

    public function scopeForClients(Builder $query, array $clientIds): Builder;

    public function scopeForLienholders(Builder $query, array $lienholderIds): Builder;

    public function scopeForDispositions(Builder $query, ?array $dispositions): Builder;

    public function scopeExcludeDisposition(Builder $query, string $disposition): Builder;

    public function scopeForCreatedBetween(Builder $query, $start = null, $end = null): Builder;

    public function scopeForHitType(Builder $query, string $hitType): Builder;
}
