<?php

namespace App\Filament\Concerns;

use App\Models\Hit;
use Illuminate\Support\Str;

trait HasClickableFilter
{
    public function addFilter(mixed $type, mixed $value): void
    {
        if (\gettype($type) === 'array') {
            // Custom Geo filter.
            foreach ($type as $key => $typeValue) {
                $formattedValue = \is_array($value) ? $value[$key] : $value;

                if ($this->getModel() === Hit::class) {
                    if (\property_exists($this, 'tableDeferredFilters')) {
                        $this->tableDeferredFilters['scan']['geo'][$typeValue] = $formattedValue;
                    }

                    if (\property_exists($this, 'tableFilters')) {
                        $this->tableFilters['scan']['geo'][$typeValue] = $formattedValue;
                    }
                } else {
                    if (\property_exists($this, 'tableDeferredFilters')) {
                        $this->tableDeferredFilters['geo'][$typeValue] = $formattedValue;
                    }

                    if (\property_exists($this, 'tableFilters')) {
                        $this->tableFilters['geo'][$typeValue] = $formattedValue;
                    }
                }
            }
        } else {
            $this->addFilterValue($type, $value);
        }

        $this->dispatch('log-filter-activity');
    }

    public function addQueryFilter(string $type, string $value, string $operator = 'equals'): void
    {
        $key = Str::random(6);

        $filter = [
            'type' => $type,
            'data' => [
                'operator' => $operator,
                'settings' => $operator === 'equalsGeo'
                    ? [
                        'lat' => \trim(Str::before($value, ',')),
                        'lon' => \trim(Str::after($value, ',')),
                    ]
                    : [
                        'text' => $value,
                    ],
            ],
        ];

        if (\property_exists($this, 'tableDeferredFilters')) {
            $this->tableDeferredFilters['queryBuilder']['rules'][$key] = $filter;
        }

        if (\property_exists($this, 'tableFilters')) {
            $this->tableFilters['queryBuilder']['rules'][$key] = $filter;
        }

        // $this->applyTableFilters();
    }

    protected function addFilterValue(mixed $type, mixed $value): void
    {
        $keys = \explode('.', $type);

        if (count($keys) > 1) {
            if (\property_exists($this, 'tableDeferredFilters')) {
                $this->tableDeferredFilters['filters'][$keys[0]][$keys[1]] = $value;
            }

            if (\property_exists($this, 'tableFilters')) {
                $this->tableFilters['filters'][$keys[0]][$keys[1]] = $value;
            }
        } else {
            if (\property_exists($this, 'tableDeferredFilters')) {
                $this->tableDeferredFilters['filters'][$type] = $value;
            }

            if (\property_exists($this, 'tableFilters')) {
                $this->tableFilters['filters'][$type] = $value;
            }
        }
    }
}
