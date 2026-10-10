<?php

namespace App\Filament\Tables\Filters;

use App\Models\User;
use Filament\Forms;
use Illuminate\Support\Collection;

/**
 * Multi-select of the org's kit-users, for the Zones list "assigned driver"
 * filter ("what does driver X cover?"). Mirrors the KitUsersFilter pattern but
 * scopes to users-with-kits rather than kits, and constrains submitted IDs to
 * the org so a tampered request can't filter by a foreign-org driver.
 */
class ZoneDriverFilter extends Forms\Components\Select
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->multiple();
        $this->searchable();
        $this->preload();
        $this->placeholder = 'Select drivers';
        $this->optionsLimit = 999;
        $this->columnSpan('full');

        $this->options = static::orgDriverOptions();
    }

    /**
     * @return Collection<int, string>
     */
    protected static function orgDriverOptions(): Collection
    {
        return User::query()
            ->where('org_id', auth()->user()->org_id)
            ->whereHas('kitSettings')
            ->orderBy('name')
            ->pluck('name', 'id');
    }

    /**
     * Constrain the submitted IDs to the org's kit-users.
     *
     * @param  array<int, int|string>  $userIds
     * @return array<int, int>
     */
    public static function format(array $userIds): array
    {
        $orgDriverIds = static::orgDriverOptions()->keys()->all();

        return collect($userIds)
            ->map(fn ($id): int => (int) $id)
            ->intersect($orgDriverIds)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int|string>  $data
     */
    public static function indicator(array $data): string
    {
        $count = \count($data);

        return 'Driver ('.($count > 10 ? '10+' : $count).')';
    }
}
