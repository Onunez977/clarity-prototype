<?php

namespace App\Ai\Tools\Concerns;

use App\Ai\PageAction;
use App\Ai\PageActionChange;
use App\Enums\HitTypeEnum;
use App\Enums\HotlistStatusEnum;
use App\Support\Otto\PageActionCopy;
use App\Support\Otto\PlatePattern;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Shared change-builders for Clarity page-search filter tools. Consuming tools
 * must also use {@see ProposesPageActions} and {@see ResolvesOttoDateRange}
 * (for requestHonoringUserTimeRange / parseOttoDateTime).
 */
trait BuildsPageSearchFilters
{
    /**
     * Set when an argument names something this org has no match for — an unknown
     * client, a driver/kit that resolves to nothing. proposeFilterChanges() then
     * abandons the proposal and returns this instead.
     *
     * Dropping the change silently would widen the search the user actually asked
     * for ("show Acme hits from the last 7 days" becoming an all-clients search),
     * and ProposesPageActions tells the assistant not to restate the individual
     * changes — so nothing else would tell them their filter was discarded.
     */
    protected ?string $filterResolutionError = null;

    /** The uppercased plate change, or null when no plate was given. */
    protected function plateChange(Request $request): ?PageActionChange
    {
        $plate = PlatePattern::normalize((string) ($request['plate'] ?? ''));
        if ($plate === '') {
            return null;
        }

        return new PageActionChange('plate', 'Plate', "Set the plate to {$plate}", $plate);
    }

    protected function vinChange(Request $request): ?PageActionChange
    {
        $vin = strtoupper(trim((string) ($request['vin'] ?? '')));
        if ($vin === '') {
            return null;
        }

        return new PageActionChange('vin', 'VIN', "Set the VIN to {$vin}", $vin);
    }

    protected function orderIdChange(Request $request): ?PageActionChange
    {
        $orderId = trim((string) ($request['order_id'] ?? ''));
        if ($orderId === '') {
            return null;
        }

        return new PageActionChange('order_id', 'Order ID', "Set the order ID to {$orderId}", $orderId);
    }

    protected function statusChange(Request $request): ?PageActionChange
    {
        $resolved = $this->resolveHotlistStatus($request['status'] ?? null);
        if ($resolved === null) {
            return null;
        }

        $enum = HotlistStatusEnum::tryFrom($resolved);
        $label = $enum !== null ? Str::headline($enum->name) : (string) $resolved;

        return new PageActionChange('status', 'Status', "Set the status to {$label}", $resolved);
    }

    protected function hitTypeChange(Request $request): ?PageActionChange
    {
        $resolved = $this->resolveHitType($request['hit_type'] ?? null);
        if ($resolved === null) {
            return null;
        }

        $enum = HitTypeEnum::tryFrom($resolved);
        $label = $enum !== null ? $enum->title() : $resolved;

        return new PageActionChange('hit_type', 'Hit type', "Filter to {$label} hits", $resolved);
    }

    protected function clientChange(Request $request): ?PageActionChange
    {
        $client = trim((string) ($request['client'] ?? ''));
        if ($client === '') {
            return null;
        }

        $ids = $this->resolveClientIds($client);
        if ($ids === []) {
            $this->filterResolutionError ??= "No client in this organization matched \"{$client}\". Tell the user that, and ask which client they meant — do not run the search without the client filter.";

            return null;
        }

        return new PageActionChange('clients', 'Client(s)', 'Filter to client '.$client, $ids);
    }

    /**
     * Kit-user filter from driver_name / kit_name / kit_id. Consuming tools must
     * also use {@see ResolvesOttoKitFilter}.
     */
    protected function kitChange(Request $request): ?PageActionChange
    {
        $kitFilter = $this->resolveOttoKitFilter($this->user, $request);

        // Only `error` means "you asked for a kit and I could not resolve it".
        // Empty ids with no error means no driver/kit hint was given at all —
        // the overwhelmingly common case — and must stay a silent no-change,
        // not an aborted proposal.
        if ($kitFilter['error'] !== null) {
            $this->filterResolutionError ??= $kitFilter['error'];

            return null;
        }

        if ($kitFilter['kit_ids'] === []) {
            return null;
        }

        $label = $kitFilter['label'] ?? 'the selected kit users';

        return new PageActionChange('kits', 'Kit users', "Filter to kit {$label}", $kitFilter['kit_ids']);
    }

    protected function booleanChange(Request $request, string $arg, string $field, string $label): ?PageActionChange
    {
        if (! array_key_exists($arg, $request->all())) {
            return null;
        }

        $on = filter_var($request[$arg], FILTER_VALIDATE_BOOLEAN);

        return new PageActionChange($field, $label, $on ? "Turn {$label} on" : "Turn {$label} off", $on);
    }

    protected function flexSearchChange(Request $request): ?PageActionChange
    {
        return $this->booleanChange($request, 'flex_search', 'flex_search', 'FlexSearch');
    }

    /**
     * Time-range change. A page-supported preset becomes a string toggle key
     * (`today`, `last_7d`); any other window becomes a {start, end} ISO-8601
     * pair (offset included) so the adapter can fill DateTogglesFilter start/end.
     *
     * The chip {@see PageActionChange::$summary} is always a human phrase
     * ("the last 7 days") — never the Filament key or Otto preset slug.
     */
    protected function timeRangeChange(Request $request): ?PageActionChange
    {
        $timezone = $this->user->timezone ?: 'UTC';
        $request = $this->requestHonoringUserTimeRange($request, $timezone);

        $start = trim((string) ($request['start_date'] ?? ''));
        $end = trim((string) ($request['end_date'] ?? ''));

        if ($start !== '' && $end !== '') {
            return $this->customRangeChange($start, $end, $timezone);
        }

        $raw = strtolower(trim((string) ($request['time_range'] ?? '')));
        if ($raw === '') {
            return null;
        }

        $preset = $this->mapOttoTimeRangeToPageToggle($raw);
        $phrase = PageActionCopy::timeRangePhrase($raw)
            ?? ($preset !== null ? PageActionCopy::timeRangePhrase($preset) : null);

        if ($preset !== null && in_array($preset, $this->pageDateTogglePresets(), true)) {
            return new PageActionChange(
                'time_range',
                'Time range',
                PageActionCopy::timeRangeSummary($phrase ?? 'the requested window'),
                $preset,
            );
        }

        [$from, $to] = $this->resolveOttoRange($request, $timezone, defaultLast30Days: false);
        if ($from === null || $to === null) {
            return null;
        }

        $change = $this->customRangeChange($from, $to, $timezone);
        if ($change === null) {
            return null;
        }

        if ($phrase !== null) {
            return new PageActionChange(
                'time_range',
                'Time range',
                PageActionCopy::timeRangeSummary($phrase),
                $change->value,
            );
        }

        return $change;
    }

    /**
     * Page DateTogglesFilter keys this tool may emit as a string preset.
     *
     * @return list<string>
     */
    protected function pageDateTogglePresets(): array
    {
        return ['today', 'yesterday', 'last_7d', 'last_30d'];
    }

    /**
     * Map an Otto `time_range` argument (or a Filament key the model copied)
     * onto the DateTogglesFilter key this page actually understands.
     */
    protected function mapOttoTimeRangeToPageToggle(string $range): ?string
    {
        return match ($range) {
            'today' => 'today',
            'yesterday' => 'yesterday',
            'last_7_days', '7d', 'last_7d' => 'last_7d',
            'last_30_days', '30d', 'last_30d' => 'last_30d',
            default => null,
        };
    }

    private function customRangeChange(string $start, string $end, string $timezone): ?PageActionChange
    {
        try {
            $from = Carbon::parse($start, $timezone)->timezone($timezone);
            $to = Carbon::parse($end, $timezone)->timezone($timezone);
        } catch (Throwable) {
            return null;
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($to->isFuture()) {
            $to = Carbon::now($timezone);
        }

        $label = $from->format('M j, Y g:i A').' – '.$to->format('M j, Y g:i A').' '.$to->format('T');

        return new PageActionChange('time_range', 'Time range', PageActionCopy::timeRangeSummary($label), [
            'start' => $from->toIso8601String(),
            'end' => $to->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, array{field: string, label: string, empty: mixed}>  $clearable
     * @return array<string, PageActionChange>
     */
    protected function clearChanges(Request $request, array $clearable): array
    {
        $changes = [];

        $tokens = collect($request['clear'] ?? [])
            ->map(fn ($token): string => (string) $token)
            ->filter(fn (string $token): bool => array_key_exists($token, $clearable))
            ->unique();

        foreach ($tokens as $token) {
            $clear = $clearable[$token];
            $changes[$clear['field']] = new PageActionChange($clear['field'], $clear['label'], "Clear the {$clear['label']} filter", $clear['empty']);
        }

        return $changes;
    }

    /**
     * @param  array<string, PageActionChange>  $changes
     */
    protected function proposeFilterChanges(array $changes, string $pageType, string $action, string $pageNoun, string $emptyGuidance): Stringable|string
    {
        $error = $this->filterResolutionError;
        $this->filterResolutionError = null;

        if ($error !== null) {
            return $error;
        }

        if ($changes === []) {
            return $emptyGuidance;
        }

        $changes = array_values($changes);
        $count = count($changes);
        $summary = "Apply {$count} ".Str::plural('change', $count)." to the {$pageNoun} page and run the search";

        return $this->proposePageAction(new PageAction(
            pageType: $pageType,
            name: $action,
            summary: $summary,
            changes: $changes,
        ));
    }

    protected function resolveHotlistStatus(mixed $status): ?int
    {
        if ($status === null || $status === '') {
            return null;
        }

        if (is_numeric($status)) {
            return HotlistStatusEnum::tryFrom((int) $status)?->value;
        }

        $normalized = Str::studly((string) $status);

        foreach (HotlistStatusEnum::cases() as $case) {
            if (strcasecmp($case->name, (string) $status) === 0 || strcasecmp($case->name, $normalized) === 0) {
                return $case->value;
            }
        }

        return null;
    }

    protected function resolveHitType(mixed $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        $raw = trim((string) $type);

        if (HitTypeEnum::tryFrom($raw) instanceof HitTypeEnum) {
            return $raw;
        }

        $normalized = Str::studly($raw);

        foreach (HitTypeEnum::cases() as $case) {
            if (strcasecmp($case->name, $raw) === 0
                || strcasecmp($case->name, $normalized) === 0
                || strcasecmp(str_replace('_', ' ', $case->name), $raw) === 0) {
                return $case->value;
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    protected function resolveClientIds(string $client): array
    {
        if ($client === '') {
            return [];
        }

        $clients = $this->user->org?->getHotlistClients() ?? collect();

        if (is_numeric($client)) {
            $id = (int) $client;

            return $clients->has($id) ? [$id] : [];
        }

        $needle = strtolower($client);

        return $clients
            ->filter(fn ($name) => str_contains(strtolower((string) $name), $needle))
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
