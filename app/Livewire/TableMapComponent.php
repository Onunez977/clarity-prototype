<?php

namespace App\Livewire;

use App\Clickhouse\Models\ClickhouseModel;
use App\Models\Scan;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

class TableMapComponent extends Component
{
    public bool $hasData = false;

    public array $oldData = [];

    public string $model = Scan::class;

    public ?string $relationship = null;

    #[On('load-records-for-map')]
    public function loadRecordsForMap(array $recordIds = [], array $center = []): void
    {
        if ($this->oldData !== $recordIds) {
            $this->oldData = $recordIds;

            $records = $this->formatRecords($recordIds);

            $this->hasData = $records !== [];

            if ($this->hasData) {
                $this->dispatch('filament-maps-widget/updateMapData', data: $records, center: $center, refresh: true);
            }
        }
    }

    public function formatRecords(array $recordIds = []): array
    {
        return $this->fetchRecords($recordIds)
            ->map(fn ($record) => ($this->relationship && $record->isRelation($this->relationship)) ? $record->{$this->relationship} : $record)
            ->filter()
            ->map(fn ($record): array => [
                'location' => [
                    'lat' => $record->latitude,
                    'lng' => $record->longitude,
                ],
                'label' => $record->ocr.' / '.$record->us_state,
                'image' => $record->vehicleImage ?? null,
                'address' => $record->address ?? null,
                'vehicle' => $record->vehicle ?? null,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Load the records for the given ids. For a ClickHouse-backed model (the
     * Hits/Scans page in ClickHouse mode) the default *_cold table is the complete
     * history — *_hot is only the last-90-day subset — so cold alone covers any id;
     * FINAL collapses the ReplacingMergeTree rows to the latest version.
     */
    protected function fetchRecords(array $recordIds): Collection
    {
        $query = $this->model::query()->whereIn('id', $recordIds);

        if (is_subclass_of($this->model, ClickhouseModel::class)) {
            $query = $query->final();
        }

        return $query->get()->toBase();
    }
}
