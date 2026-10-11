<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\OpportunityCase;
use App\Models\OpportunityDispatch;
use App\Models\OpportunityDriver;
use App\Support\Prototype\OpportunityBoard;
use App\Support\Scout\Haversine;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

class Opportunities extends Page
{
    protected static ?int $navigationSort = 0;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static string|\UnitEnum|null $navigationGroup = 'Search';

    protected static ?string $navigationLabel = 'Opportunities';

    protected static ?string $title = 'Opportunities';

    protected static ?string $slug = 'opportunities';

    protected string $view = 'filament.pages.opportunities';

    #[Url(except: 'queue')]
    public string $viewMode = 'queue';

    #[Url(except: 'score')]
    public string $sort = 'score';

    #[Url(except: 'desc')]
    public string $dir = 'desc';

    #[Url(except: '')]
    public string $q = '';

    #[Url(except: '')]
    public string $client = '';

    #[Url(except: '')]
    public string $branch = '';

    #[Url(except: '')]
    public string $band = '';

    #[Url(except: '')]
    public string $coverage = '';

    #[Url(except: '')]
    public string $recency = '';

    #[Url(except: false)]
    public bool $unassigned = false;

    #[Url(except: null)]
    public ?int $case = null;

    /** @var list<int> */
    public array $selected = [];

    public bool $showTour = true;

    public bool $showDispatch = false;

    /** @var list<int> */
    public array $dispatchCaseIds = [];

    public ?int $dispatchDriverId = null;

    public string $dispatchPriority = 'high';

    public string $dispatchDue = 'overnight';

    public string $dispatchNote = '';

    public function mount(): void
    {
        $this->showTour = ! session()->has('opportunities.tour_dismissed');
    }

    public function dismissTour(): void
    {
        session(['opportunities.tour_dismissed' => true]);
        $this->showTour = false;
    }

    public function updatedSelected(): void
    {
        $this->selected = array_values(array_unique(array_map('intval', $this->selected)));
    }

    public function sortBy(string $column): void
    {
        $allowed = ['score', 'plate', 'client', 'order_age', 'newest_hit', 'address', 'distance', 'driver', 'status'];
        if (! in_array($column, $allowed, true)) {
            return;
        }
        if ($this->sort === $column) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';

            return;
        }
        $this->sort = $column;
        $this->dir = in_array($column, ['plate', 'client', 'address', 'driver', 'status'], true) ? 'asc' : 'desc';
    }

    public function clearFilters(): void
    {
        $this->q = '';
        $this->client = '';
        $this->branch = '';
        $this->band = '';
        $this->coverage = '';
        $this->recency = '';
        $this->unassigned = false;
        $this->selected = [];
    }

    public function toggleAll(): void
    {
        $ids = array_column($this->board()['rows'], 'id');
        $selected = array_map('intval', $this->selected);
        $this->selected = $ids !== [] && count(array_diff($ids, $selected)) === 0 ? [] : $ids;
    }

    public function openCase(int $id): void
    {
        $this->caseForOrg($id);
        $this->case = $id;
    }

    public function closeCase(): void
    {
        $this->case = null;
    }

    public function openDispatch(?int $caseId = null, ?int $driverId = null): void
    {
        $ids = $caseId ? [$caseId] : array_map('intval', $this->selected);
        $ids = array_values(array_filter($ids));
        if ($ids === []) {
            return;
        }
        foreach ($ids as $id) {
            $this->caseForOrg($id);
        }
        $this->dispatchCaseIds = $ids;
        $this->dispatchDriverId = $driverId;
        $this->dispatchPriority = 'high';
        $this->dispatchDue = 'overnight';
        $this->dispatchNote = '';
        $this->showDispatch = true;
    }

    public function closeDispatch(): void
    {
        $this->showDispatch = false;
    }

    public function saveDispatch(): void
    {
        $this->validate([
            'dispatchDriverId' => ['required', 'integer', 'min:1'],
            'dispatchPriority' => ['required', 'in:normal,high'],
            'dispatchDue' => ['required', 'in:tonight,overnight,tomorrow'],
            'dispatchNote' => ['nullable', 'string', 'max:500'],
        ]);

        $driver = $this->driver((int) $this->dispatchDriverId);
        $cases = OpportunityCase::query()
            ->where('org_id', $this->orgId())
            ->whereIn('id', $this->dispatchCaseIds)
            ->get();

        if ($cases->isEmpty()) {
            return;
        }

        $next = (int) OpportunityDispatch::query()->where('driver_id', $driver->id)->max('stop_order');
        $plates = [];
        foreach ($cases as $case) {
            $existing = OpportunityDispatch::query()->where('case_id', $case->id)->first();
            $sameDriver = $existing && (int) $existing->driver_id === (int) $driver->id;
            if (! $sameDriver) {
                $next++;
            }
            $log = 'Stub notification for '.$driver->name.' ('.$driver->kit_name.'): '.$case->plate
                .', '.$this->dueLabel($this->dispatchDue).'. Nothing was sent.';
            OpportunityDispatch::query()->updateOrCreate(
                ['case_id' => $case->id],
                [
                    'driver_id' => $driver->id,
                    'priority' => $this->dispatchPriority,
                    'due_window' => $this->dispatchDue,
                    'note' => $this->dispatchNote !== '' ? $this->dispatchNote : null,
                    'status' => 'dispatched',
                    'stop_order' => $sameDriver ? $existing->stop_order : $next,
                    'notification_log' => $log,
                ],
            );
            $plates[] = $case->plate;
        }

        $this->showDispatch = false;
        $this->selected = [];

        Notification::make()
            ->title('Dispatched to '.$driver->name)
            ->body('Stub notification logged for '.$driver->kit_name.' ('.implode(', ', $plates).'). Nothing was sent.')
            ->success()
            ->send();
    }

    public function setStatus(int $caseId, string $status): void
    {
        if (! in_array($status, ['checked_in', 'recovered', 'not_found'], true)) {
            return;
        }
        $dispatch = $this->dispatchForOrg($caseId);
        $dispatch->status = $status;
        $dispatch->save();

        Notification::make()
            ->title(OpportunityBoard::statusLabel($status))
            ->body($dispatch->case?->plate.' updated. Nothing was sent to the driver.')
            ->success()
            ->send();
    }

    public function moveStop(int $caseId, int $driverId, ?int $beforeCaseId = null): void
    {
        $dispatch = $this->dispatchForOrg($caseId);
        if ($beforeCaseId === $caseId && (int) $dispatch->driver_id === $driverId) {
            return;
        }
        $target = $this->driver($driverId);
        $oldDriverId = (int) $dispatch->driver_id;
        $dispatch->driver_id = $target->id;
        $dispatch->save();
        $this->renumber($oldDriverId);
        $this->place((int) $target->id, $caseId, $beforeCaseId);
    }

    public function nudgeStop(int $caseId, int $direction): void
    {
        $dispatch = $this->dispatchForOrg($caseId);
        $ids = $this->activeIds((int) $dispatch->driver_id);
        $index = array_search($caseId, $ids, true);
        if ($index === false) {
            return;
        }
        $swap = $index + ($direction < 0 ? -1 : 1);
        if ($swap < 0 || $swap >= count($ids)) {
            return;
        }
        $ids[$index] = $ids[$swap];
        $ids[$swap] = $caseId;
        $this->writeOrder((int) $dispatch->driver_id, $ids);
    }

    public function suggestOrder(int $driverId): void
    {
        $board = $this->board();
        $driver = collect($board['drivers'])->firstWhere('id', $driverId);
        $column = collect($board['plan']['columns'])->firstWhere('id', $driverId);
        if (! $driver || ! $column || $column['stops'] === []) {
            return;
        }

        $remaining = $column['stops'];
        $ordered = [];
        $lat = (float) $driver['lat'];
        $lng = (float) $driver['lng'];
        while ($remaining !== []) {
            $bestIndex = 0;
            $bestMiles = INF;
            foreach ($remaining as $index => $stop) {
                $miles = ($stop['lat'] === null || $stop['lng'] === null)
                    ? INF
                    : Haversine::feet([$lng, $lat], [(float) $stop['lng'], (float) $stop['lat']]) / 5280;
                if ($miles < $bestMiles) {
                    $bestMiles = $miles;
                    $bestIndex = $index;
                }
            }
            $chosen = $remaining[$bestIndex];
            $ordered[] = $chosen;
            if ($chosen['lat'] !== null && $chosen['lng'] !== null) {
                $lat = (float) $chosen['lat'];
                $lng = (float) $chosen['lng'];
            }
            unset($remaining[$bestIndex]);
            $remaining = array_values($remaining);
        }

        $this->writeOrder($driverId, array_map(fn (array $stop): int => (int) $stop['case_id'], $ordered));

        Notification::make()
            ->title('Straight-line order saved')
            ->body('Stops are ordered by straight-line miles, not drive time.')
            ->success()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    public function board(): array
    {
        return OpportunityBoard::present(
            $this->orgId(),
            [
                'q' => $this->q,
                'client' => $this->client,
                'branch' => $this->branch,
                'band' => $this->band,
                'coverage' => $this->coverage,
                'recency' => $this->recency,
                'unassigned' => $this->unassigned,
            ],
            $this->sort,
            $this->dir,
            $this->case,
        );
    }

    private function orgId(): int
    {
        return (int) auth()->user()->org_id;
    }

    private function caseForOrg(int $id): OpportunityCase
    {
        return OpportunityCase::query()->where('org_id', $this->orgId())->findOrFail($id);
    }

    private function driver(int $id): OpportunityDriver
    {
        return OpportunityDriver::query()->where('org_id', $this->orgId())->findOrFail($id);
    }

    private function dispatchForOrg(int $caseId): OpportunityDispatch
    {
        $this->caseForOrg($caseId);

        return OpportunityDispatch::query()->where('case_id', $caseId)->firstOrFail();
    }

    /**
     * @return list<int>
     */
    private function activeIds(int $driverId): array
    {
        return OpportunityDispatch::query()
            ->where('driver_id', $driverId)
            ->whereIn('status', ['dispatched', 'checked_in'])
            ->orderBy('stop_order')
            ->pluck('case_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function renumber(int $driverId): void
    {
        $this->writeOrder($driverId, $this->activeIds($driverId));
    }

    private function place(int $driverId, int $caseId, ?int $beforeCaseId): void
    {
        $ids = array_values(array_filter(
            $this->activeIds($driverId),
            fn (int $id): bool => $id !== $caseId,
        ));
        if ($beforeCaseId) {
            $index = array_search($beforeCaseId, $ids, true);
            if ($index === false) {
                $ids[] = $caseId;
            } else {
                array_splice($ids, (int) $index, 0, [$caseId]);
            }
        } else {
            $ids[] = $caseId;
        }
        $this->writeOrder($driverId, $ids);
    }

    /**
     * @param  list<int>  $ids
     */
    private function writeOrder(int $driverId, array $ids): void
    {
        foreach ($ids as $index => $id) {
            OpportunityDispatch::query()->where('case_id', $id)->update([
                'driver_id' => $driverId,
                'stop_order' => $index + 1,
            ]);
        }
    }

    private function dueLabel(string $due): string
    {
        return OpportunityBoard::dueLabel($due) ?? $due;
    }
}
