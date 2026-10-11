<?php

namespace App\Filament\Resources;

use App\Clickhouse\Queries\OrgKitCameraNamesQuery;
use App\Clickhouse\Queries\OrgKitLastScanQuery;
use App\Clickhouse\Queries\OrgKitReportingStatusQuery;
use App\Filament\Resources\GlKitsResource\Pages;
use App\Models\Kit;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\KitType;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use InvalidArgumentException;

/**
 * Read-only list of the GLs (physical Genetec cameras) the authenticated org's
 * kits scan with, one row per GL. A `kit` is a login identity for a person and
 * kit-to-GL is many-to-many, so the kit-per-row shape this page shipped with
 * showed 272 people where org 1143 owns 49 cameras (DEV-2486).
 *
 * Rows come from {@see OrgKitCameraNamesQuery}, not from `getEloquentQuery()`:
 * Eloquent cannot sort a computed column, and both computed columns must sort.
 * The trade-off, accepted deliberately: row identity now depends on a ClickHouse
 * read, so a failed lookup renders an empty table rather than degrading each row
 * to "Unknown" the way the PostgreSQL-backed version did. `reportClickhouseFailure()`
 * keeps that outage visible in error tracking.
 *
 * Deliberately owned-only (`org_id`, not the `Shared` pivot) so "Company owner"
 * always renders the viewing org's own name — owned-only, not the Shared pivot.
 */
class GlKitsResource extends Resource
{
    protected static ?string $model = Kit::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'GL Kits';

    protected static ?string $pluralModelLabel = 'GL Kits';

    protected static ?string $modelLabel = 'GL Kit';

    protected static ?string $slug = 'gl-kits';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    /** Ascending sort shows the best-known state first (DEV-2486). */
    private const REPORTING_LABELS = [0 => 'Reporting', 1 => 'Not Reporting', 2 => 'Unknown'];

    private const REPORTING_TOOLTIPS = [
        'Reporting' => 'At least one kit user on this GL sent a GPS position in the last 14 days.',
        'Not Reporting' => 'Every kit user on this GL was checked. None sent a GPS position in the last 14 days.',
        'Unknown' => 'No scanner mapping to look up, or the lookup failed. Different from "Not Reporting".',
    ];

    private const LAST_SCAN_TOOLTIPS = [
        'Never' => 'No scan has ever been recorded for any kit user on this GL.',
        'Unknown' => 'The last-scan lookup failed, so this is not known. It does not mean "never".',
    ];

    public static function canAccess(): bool
    {
        return auth()->user()->hasRole(RoleEnum::ADMIN->value);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->records(fn (int|string $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection, ?string $search): LengthAwarePaginator => static::glRecords($page, $recordsPerPage, $sortColumn, $sortDirection, $search))
            ->columns([
                Tables\Columns\TextColumn::make('gl')
                    ->label('GL')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('company_owner')
                    ->label('Company Owner'),

                Tables\Columns\TextColumn::make('last_scan')
                    ->label('Last Scan Received')
                    ->sortable()
                    ->headerTooltip('The most recent scan across every kit user mapped to this GL, shown in your timezone.')
                    ->tooltip(fn (string $state): ?string => self::LAST_SCAN_TOOLTIPS[$state] ?? null),

                Tables\Columns\TextColumn::make('reporting')
                    ->label('Reporting (14d)')
                    ->badge()
                    ->sortable()
                    ->headerTooltip('Whether any kit user on this GL has sent a GPS position in the last 14 days. Hover a badge for what that state means.')
                    ->tooltip(fn (string $state): ?string => self::REPORTING_TOOLTIPS[$state] ?? null)
                    ->color(fn (string $state): string => match ($state) {
                        'Reporting' => 'success',
                        'Not Reporting' => 'gray',
                        default => 'warning',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'Reporting' => 'heroicon-o-check-circle',
                        'Not Reporting' => 'heroicon-o-x-circle',
                        default => 'heroicon-o-question-mark-circle',
                    }),
            ])
            // ListRecords::makeTable() installs recordAction/recordUrl closures typed
            // `Model $record`, and the table view calls them for every rendered row.
            // Filament injects by parameter name, so an array record would hit that
            // Model parameter and throw. Resource::configureTable() runs table() last,
            // so nulling them here wins.
            ->recordAction(null)
            ->recordUrl(null)
            ->defaultPaginationPageOption(100);
        // No ->defaultSort(): Filament reads it only in applySortingToTableQuery(), which a
        // ->records() table never reaches. The GL-ascending default lives in glRecords()'s
        // match arm below, so setting it here would read as the source of truth and not be one.
    }

    /** @return Builder<Kit> */
    public static function getEloquentQuery(): Builder
    {
        return static::glKitsQuery((int) auth()->user()->org_id);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGlKits::route('/'),
        ];
    }

    /**
     * The org's GL kits in PostgreSQL. Single definition because it is the ONLY
     * tenancy boundary on this page: {@see OrgKitCameraNamesQuery} reads a table
     * with no `org_id`, so the kit list is what scopes it.
     *
     * @return Builder<Kit>
     */
    protected static function glKitsQuery(int $orgId): Builder
    {
        return parent::getEloquentQuery()
            ->where('org_id', $orgId)
            ->where('kit_type', KitType::GENETEC->value)
            ->excludingDeleted();
    }

    /**
     * One row per GL, sorted and paginated here because `->records()` bypasses
     * Eloquent entirely. An empty or failed GL lookup both render an empty table —
     * see the class docblock.
     */
    protected static function glRecords(int|string $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection, ?string $search): LengthAwarePaginator
    {
        // Filament injects these as int|string and hands the page through unsanitised, so the
        // Paginator::isValidPageNumber() guard the Eloquent path used to provide is gone. Without
        // clamping, ?page=0 becomes a negative slice offset and silently serves the LAST page's
        // rows under a "page 1" footer; a non-numeric page would TypeError.
        $page = max(1, (int) $page);
        $recordsPerPage = max(1, (int) $recordsPerPage);

        $orgId = (int) auth()->user()->org_id;
        $glMap = static::glMap($orgId);

        if ($glMap === null || $glMap === []) {
            return new LengthAwarePaginator([], 0, $recordsPerPage, $page);
        }

        $kits = static::glKits($orgId)->keyBy('id');
        $lastScans = static::lastScanMap($orgId);
        $reporting = static::reportingMap($orgId);
        $owner = (string) (auth()->user()->org->name ?? '');

        $rows = collect($glMap)
            ->map(function (array $kitIds, string|int $gl) use ($kits, $lastScans, $reporting, $owner): array {
                [$lastScan, $lastScanSort] = static::lastScanCell($kitIds, $lastScans);
                $rank = static::reportingRank($kitIds, $kits, $reporting);

                return [
                    'gl' => (string) $gl,
                    // Byte order would exile a lowercase-initial GL below every uppercase one;
                    // the PostgreSQL-collated column this replaced sorted case-insensitively.
                    'gl_sort' => Str::lower((string) $gl),
                    'company_owner' => $owner,
                    'last_scan' => $lastScan,
                    'last_scan_sort' => $lastScanSort,
                    'reporting' => self::REPORTING_LABELS[$rank],
                    'reporting_sort' => (string) $rank,
                ];
            })
            ->keyBy('gl');

        if (filled($search)) {
            $needle = Str::lower($search);
            $rows = $rows->filter(fn (array $row): bool => str_contains(Str::lower($row['gl']), $needle));
        }

        $sortKey = match ($sortColumn) {
            'last_scan' => 'last_scan_sort',
            'reporting' => 'reporting_sort',
            default => 'gl_sort',
        };

        // SORT_STRING, not the default SORT_REGULAR: `<=>` compares two numeric strings
        // NUMERICALLY and falls back to strcmp for mixed pairs, which is not a total order — a
        // fleet containing "9", "10" and "999" then renders in an order that depends on the
        // ClickHouse row order, so the same GLs re-sort differently as the org's kits change.
        // strcmp is also correct for the '0'.$ts / '1' / '2' and '0'/'1'/'2' sentinels.
        $rows = $rows->sortBy([
            [$sortKey, $sortDirection === 'desc' ? 'desc' : 'asc'],
            ['gl_sort', 'asc'],
        ], SORT_STRING);

        return new LengthAwarePaginator(
            $rows->slice(($page - 1) * $recordsPerPage, $recordsPerPage),
            $rows->count(),
            $recordsPerPage,
            $page
        );
    }

    /**
     * Newest scan across the GL's kits, with a sortable companion value. The rank
     * prefix keeps real timestamps ahead of "Never" and "Unknown" while sorting
     * ascending; the timestamps themselves are fixed-width UTC strings, so
     * lexicographic order is chronological order.
     *
     * @param  list<int>  $kitIds
     * @param  array<int, string>|null  $lastScans
     * @return array{0: string, 1: string}
     */
    protected static function lastScanCell(array $kitIds, ?array $lastScans): array
    {
        if ($lastScans === null) {
            return ['Unknown', '2'];
        }

        $latest = null;

        foreach ($kitIds as $kitId) {
            $value = $lastScans[$kitId] ?? null;

            if ($value !== null && ($latest === null || $value > $latest)) {
                $latest = $value;
            }
        }

        if ($latest === null) {
            return ['Never', '1'];
        }

        $formatted = Carbon::parse($latest, 'UTC')
            ->timezone(auth()->user()->timezone)
            ->format(config('app.date_format'));

        return [$formatted, '0'.$latest];
    }

    /**
     * Best state across the GL's kits: any kit reporting makes the GL Reporting.
     * "Unknown" is reserved for a GL where nothing was actually checked — the read
     * failed, or no mapped kit has a scanner mapping. Collapsing that into "Not
     * Reporting" would state a negative we never established, and hide it behind
     * the ~47% of GL kits that legitimately don't report.
     *
     * @param  list<int>  $kitIds
     * @param  Collection<int, Kit>  $kits
     * @param  array<array-key, string>|null  $reporting
     */
    protected static function reportingRank(array $kitIds, Collection $kits, ?array $reporting): int
    {
        $rank = 2;

        foreach ($kitIds as $kitId) {
            $mapping = strtolower((string) ($kits[$kitId]->kit_mapping ?? ''));

            if ($mapping === '' || $reporting === null) {
                continue;
            }

            $rank = min($rank, array_key_exists($mapping, $reporting) ? 0 : 1);
        }

        return $rank;
    }

    /**
     * Memoized (per request) map of camera_name => kit_ids for the org's GL kits.
     * `null` means the ClickHouse read itself failed; `[]` means it succeeded and
     * found nothing. Same per-org memo keying as {@see lastScanMap()}.
     *
     * Calls `handle()` rather than `query()` deliberately: {@see ClickhouseQuery::query()}
     * swallows Throwable to `[]`, which would make a failed read indistinguishable
     * from an org that owns no GLs and would lose the log line as well.
     *
     * @return array<array-key, list<int>>|null
     */
    protected static function glMap(int $orgId): ?array
    {
        return once(function () use ($orgId): ?array {
            $kitIds = static::glKits($orgId)->pluck('id')->all();

            if ($kitIds === []) {
                return [];
            }

            try {
                return OrgKitCameraNamesQuery::handle(['kit_ids' => $kitIds]);
            } catch (InvalidArgumentException $e) {
                throw $e;
            } catch (\Throwable $e) {
                static::reportClickhouseFailure('camera-names', $orgId, $e);

                return null;
            }
        });
    }

    /**
     * Memoized (per request) map of kit_id => last_scan for the org's GL kits.
     * `null` means the ClickHouse read itself failed; `[]` means it succeeded and
     * found nothing. The org is captured into the closure so it forms part of the
     * memo key — `Onceable::hashFromTrace()` folds the closure's used variables into
     * the hash, so an unkeyed memo would serve one org's map to another the moment
     * two orgs are resolved in a single process (worker mode, a queued job, or a
     * test that renders as two users). Same idiom as {@see UsersResource}.
     *
     * @return array<int, string>|null
     */
    protected static function lastScanMap(int $orgId): ?array
    {
        return once(function () use ($orgId): ?array {
            $kitIds = static::glKits($orgId)->pluck('id')->all();

            if ($kitIds === []) {
                return [];
            }

            try {
                return OrgKitLastScanQuery::handle(['org_id' => $orgId, 'kit_ids' => $kitIds]);
            } catch (InvalidArgumentException $e) {
                throw $e;
            } catch (\Throwable $e) {
                static::reportClickhouseFailure('last-scan', $orgId, $e);

                return null;
            }
        });
    }

    /**
     * Memoized (per request) map of lowercased kit_mapping => latest GPS timestamp
     * for the org's GL kits, over the query's default 14-day window. `null` means
     * the ClickHouse read itself failed; `[]` means it succeeded and found nothing.
     *
     * @return array<array-key, string>|null
     */
    protected static function reportingMap(int $orgId): ?array
    {
        return once(function () use ($orgId): ?array {
            $mappings = static::glKits($orgId)->pluck('kit_mapping')->all();

            if ($mappings === []) {
                return [];
            }

            try {
                return OrgKitReportingStatusQuery::handle(['mappings' => $mappings]);
            } catch (\Throwable $e) {
                static::reportClickhouseFailure('reporting-status', $orgId, $e);

                return null;
            }
        });
    }

    /**
     * Catching the read locally is what lets the column show "Unknown", but it also
     * bypasses {@see ClickhouseQuery::query()}'s `report()`. Report here too, or an
     * outage becomes invisible to error tracking. Programming errors
     * (InvalidArgumentException) are deliberately re-thrown rather than degraded —
     * a wiring bug should be loud, not rendered as "Unknown".
     */
    protected static function reportClickhouseFailure(string $lookup, int $orgId, \Throwable $e): void
    {
        Log::warning("gl-kits {$lookup} read failed; rendering unknown", [
            'org_id' => $orgId,
            'exception' => $e->getMessage(),
        ]);

        report($e);
    }

    /**
     * Memoized (per request) set of the org's active GL kits, used to derive
     * the id/mapping lists the ClickHouse batch lookups need. Resolved from
     * PostgreSQL, never from ClickHouse: {@see OrgKitLastScanQuery} deliberately
     * does not derive its own scope.
     *
     * @return Collection<int, Kit>
     */
    protected static function glKits(int $orgId): Collection
    {
        return once(function () use ($orgId): Collection {
            return static::glKitsQuery($orgId)->get();
        });
    }
}
