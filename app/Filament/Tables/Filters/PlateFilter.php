<?php

namespace App\Filament\Tables\Filters;

use App\Clickhouse\Models\ClickhouseModel;
use App\Enums\FeatureEnum;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

class PlateFilter extends Forms\Components\TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->minLength(4);

        $this->autocapitalize('characters');

        $this->helperText('Press ENTER to search.');

        $this->extraInputAttributes([
            '@keyup' => '$nextTick(() => $event.target.value = ($event.target.value.replaceAll(/[^a-zA-Z0-9]/g, \'\')).toUpperCase())',
            'x-on:blur' => $this->getLivewireStatePath().' = ('.$this->getLivewireStatePath().'.replaceAll(/[^a-zA-Z0-9]/g, \'\')).toUpperCase()',
        ]);
    }

    private function getLivewireStatePath(): string
    {
        return '$wire.tableDeferredFilters.filters.'.$this->getName();
    }

    public static function modifyQuery(Builder $query, string $column, ?string $plate = null, bool $flexSearch = false): Builder
    {
        $plate = Str::of($plate)->trim()->upper()->toString();

        $flexSearchAvailable = Feature::active(FeatureEnum::FLEX_SEARCH->slug())
            || session()->has('admin_impersonator');

        if ($flexSearchAvailable && $flexSearch) {
            return $query->getModel() instanceof ClickhouseModel
                ? $query->whereRaw("{$column} IN (SELECT arrayJoin(plate_variants('".addslashes($plate)."')))")
                : $query->whereRaw($column.' = ANY(public.get_license_plate_variants(?))', [$plate]);
        }

        return $query->where($column, 'LIKE', "%{$plate}%");
    }
}
