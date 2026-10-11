<?php

namespace App\Jobs;

use App\Actions\Activity\ActivityFactory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class LogFilterActivity implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $log,
        public array $filterState,
        public array $types,
        public User $user,
        public array $session = [],
    ) {
        $this->onQueue('{low}');
    }

    public function handle(): void
    {
        $data = [];
        $filterState = $this->formatState();

        if ($filterState !== []) {
            foreach ($filterState as $key => $item) {
                if (isset($item['type']) && \in_array($item['type'], \array_keys($this->types))) {
                    $value = $item['data']['settings']['text']
                        ?? $item['data']['settings']['value']
                        ?? $item['data']['settings']['values']
                        ?? null;

                    if ($value !== null) {
                        $data[$this->types[$item['type']]][] = $value;
                    }
                } elseif (\in_array($key, \array_keys($this->types)) && $item !== null) {
                    $data[$this->types[$key]][] = $item;
                }
            }

            if ($data !== []) {
                ActivityFactory::make()->log(
                    logName: $this->log,
                    data: $data,
                    causer: $this->user,
                    session: $this->session,
                );
            }
        }
    }

    protected function formatState(): array
    {
        $filterState = [];

        // Flatten filter state into manageable array.
        if (count($this->filterState) > 0) {
            foreach ($this->filterState as $filterKey => $filters) {
                foreach ($filters as $key => $filter) {
                    if (\is_array($filter)) {
                        foreach ($filter as $type => $value) {
                            $filterState[$key.'.'.$type] = $this->formatValue($value);
                        }
                    } else {
                        if ($filterKey !== 'filters' && $filterKey !== 'queryBuilder') {
                            $filterState[$filterKey.'.'.$key] = $this->formatValue($filter);
                        } else {
                            $filterState[$key] = $this->formatValue($filter);
                        }
                    }
                }
            }
        }

        return $filterState;
    }

    protected function formatValue(mixed $value): mixed
    {
        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }

        return $value;
    }
}
