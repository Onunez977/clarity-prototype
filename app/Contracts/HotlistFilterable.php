<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface HotlistFilterable
{
    public function scopeForStatus(Builder $query, int $status): Builder;

    public function scopeForVin(Builder $query, string $vin): Builder;

    public function scopeForPlate(Builder $query, string $plate, bool $flexSearch = false): Builder;

    public function scopeForOrderId(Builder $query, string $orderId): Builder;

    public function scopeForClients(Builder $query, array $clientIds): Builder;

    public function scopeMissingPlate(Builder $query): Builder;
}
