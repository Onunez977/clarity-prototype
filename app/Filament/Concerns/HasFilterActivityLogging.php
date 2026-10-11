<?php

namespace App\Filament\Concerns;

use App\Jobs\LogFilterActivity;
use Livewire\Attributes\On;

trait HasFilterActivityLogging
{
    #[On('log-filter-activity')]
    public function updatedPage(): void
    {
        if (\property_exists($this, 'loggedFilters') && $this->getTable()->isFiltered()) {
            LogFilterActivity::dispatch(
                log: $this->getTable()->getPluralModelLabel(),
                filterState: $this->formatFilterState(),
                types: $this->loggedFilters,
                user: auth()->user(),
                session: [
                    'impersonator' => session()->get('impersonator'),
                    'clientIp' => request()->getClientIp(),
                    'userAgent' => request()->userAgent(),
                ],
            );
        }
    }

    private function formatFilterState(): array
    {
        $state = [];
        $filters = $this->getTable()->getFilters();

        foreach ($filters as $filter) {
            $state[$filter->getName()] = $filter->getState();
        }

        return $state;
    }
}
