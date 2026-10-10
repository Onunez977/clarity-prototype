<?php

namespace App\Filament\Concerns;

use Carbon\Carbon;
use Throwable;

/**
 * Shared Otto → Filament table-filter writes for Scans / Hits / Hotlist list pages.
 */
trait AppliesOttoPageFilters
{
    /**
     * @return array<string, mixed>
     */
    protected function ottoFilterBag(): array
    {
        if (method_exists($this, 'getTable') && $this->getTable()->hasDeferredFilters() && is_array($this->tableDeferredFilters ?? null)) {
            return $this->tableDeferredFilters;
        }

        return is_array($this->tableFilters ?? null) ? $this->tableFilters : [];
    }

    protected function ottoFilterValue(string $path, mixed $default = null): mixed
    {
        return data_get($this->ottoFilterBag(), $path, $default);
    }

    protected function setOttoTableFilter(string $path, mixed $value): void
    {
        $this->mergeOttoFilterDefaults();

        data_set($this->tableFilters, $path, $value);

        if (property_exists($this, 'tableDeferredFilters')) {
            if (! is_array($this->tableDeferredFilters)) {
                $this->tableDeferredFilters = [];
            }

            data_set($this->tableDeferredFilters, $path, $value);
        }
    }

    /**
     * Filament filter `indicateUsing` closures index keys like `kits` / `polling`
     * without null-coalescing. Seed each filter's default state before writing
     * Otto's sparse payload so applyTableFilters does not see a partial bag.
     */
    protected function mergeOttoFilterDefaults(): void
    {
        if (! is_array($this->tableFilters)) {
            $this->tableFilters = [];
        }

        if (! method_exists($this, 'getTable')) {
            return;
        }

        try {
            foreach ($this->getTable()->getFilters() as $filter) {
                $defaults = $filter->getState();
                if (! is_array($defaults)) {
                    continue;
                }

                $name = $filter->getName();
                $current = data_get($this->tableFilters, $name);
                $merged = is_array($current)
                    ? array_replace_recursive($defaults, $current)
                    : $defaults;

                data_set($this->tableFilters, $name, $merged);
            }
        } catch (Throwable) {
            // Table may not be booted on a stubbed adapter.
        }

        if (property_exists($this, 'tableDeferredFilters')) {
            if (! is_array($this->tableDeferredFilters)) {
                $this->tableDeferredFilters = [];
            }

            $this->tableDeferredFilters = array_replace_recursive(
                $this->tableFilters,
                $this->tableDeferredFilters,
            );
        }
    }

    /**
     * Apply an Otto time-range change onto a DateTogglesFilter named $filterName
     * (e.g. "scanned_at" or "scans.scanned_at"). String presets are page toggle
     * keys; arrays are {start, end} ISO-8601 (offset wins, naive = user TZ).
     */
    protected function applyOttoDateFilter(string $filterName, mixed $value, bool $supportsAdvanced = true): void
    {
        $tz = auth()->user()?->timezone ?: 'UTC';

        if (is_array($value) && filled($value['start'] ?? null) && filled($value['end'] ?? null)) {
            $start = $this->parseOttoFilterDateTime((string) $value['start'], $tz);
            $end = $this->parseOttoFilterDateTime((string) $value['end'], $tz);

            if ($start === null || $end === null) {
                return;
            }

            $this->setOttoTableFilter($filterName.'.start', $start->format('Y-m-d H:i:s'));
            $this->setOttoTableFilter($filterName.'.end', $end->format('Y-m-d H:i:s'));

            if ($supportsAdvanced) {
                $this->setOttoTableFilter($filterName.'.date_toggles', 'advanced');
            } else {
                $this->setOttoTableFilter($filterName.'.date_toggles', null);
            }

            return;
        }

        $preset = (string) $value;

        if ($preset === '') {
            $this->setOttoTableFilter($filterName.'.date_toggles', null);
            $this->setOttoTableFilter($filterName.'.start', null);
            $this->setOttoTableFilter($filterName.'.end', null);

            return;
        }

        $this->setOttoTableFilter($filterName.'.date_toggles', $preset);
        [$start, $end] = $this->ottoPresetBounds($preset, $tz);
        $this->setOttoTableFilter($filterName.'.start', $start);
        $this->setOttoTableFilter($filterName.'.end', $end);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    protected function ottoPresetBounds(string $preset, string $tz): array
    {
        $now = now($tz);

        return match ($preset) {
            'today' => [$now->copy()->startOfDay()->format('Y-m-d H:i:s'), $now->copy()->format('Y-m-d H:i:s')],
            'yesterday' => [
                $now->copy()->subDay()->startOfDay()->format('Y-m-d H:i:s'),
                $now->copy()->subDay()->endOfDay()->format('Y-m-d H:i:s'),
            ],
            'last_7d' => [$now->copy()->subDays(7)->startOfDay()->format('Y-m-d H:i:s'), $now->copy()->format('Y-m-d H:i:s')],
            'last_30d' => [$now->copy()->subDays(30)->startOfDay()->format('Y-m-d H:i:s'), $now->copy()->format('Y-m-d H:i:s')],
            'last_3m' => [$now->copy()->startOfMonth()->subMonths(3)->startOfDay()->format('Y-m-d H:i:s'), $now->copy()->format('Y-m-d H:i:s')],
            'last_6m' => [$now->copy()->startOfMonth()->subMonths(6)->startOfDay()->format('Y-m-d H:i:s'), $now->copy()->format('Y-m-d H:i:s')],
            'year' => [$now->copy()->subYear()->startOfDay()->format('Y-m-d H:i:s'), $now->copy()->format('Y-m-d H:i:s')],
            'all' => [null, null],
            default => [null, null],
        };
    }

    protected function parseOttoFilterDateTime(string $value, string $tz): ?Carbon
    {
        try {
            return Carbon::parse($value, $tz)->timezone($tz);
        } catch (Throwable) {
            return null;
        }
    }

    protected function commitOttoTableFilters(): void
    {
        $this->mergeOttoFilterDefaults();

        if (method_exists($this, 'getTableFiltersForm')) {
            try {
                $this->getTableFiltersForm()->fill($this->tableFilters ?? []);
            } catch (Throwable) {
                // Form may not be hydrated yet on a stubbed test page.
            }
        }

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }

        if (method_exists($this, 'applyTableFilters')) {
            $this->applyTableFilters();
        }

        $this->dispatch('log-filter-activity');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $fieldPaths  payload field => tableFilters path
     */
    protected function applyOttoFilterPayload(array $payload, array $fieldPaths): void
    {
        foreach ($fieldPaths as $field => $path) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $this->setOttoTableFilter($path, $payload[$field]);
        }
    }

    protected function ottoDateFilterState(string $filterName): mixed
    {
        $toggle = $this->ottoFilterValue($filterName.'.date_toggles');

        if (is_string($toggle) && $toggle !== '' && $toggle !== 'advanced') {
            return $toggle;
        }

        $start = $this->ottoFilterValue($filterName.'.start');
        $end = $this->ottoFilterValue($filterName.'.end');

        if (filled($start) && filled($end)) {
            return ['start' => $start, 'end' => $end];
        }

        return null;
    }
}
