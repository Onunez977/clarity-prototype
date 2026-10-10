---
name: filament-resource-generator
description: Use when creating a new Filament resource, adding list pages with filters, or implementing table-based admin interfaces
---

# Filament Resource Generator

Generate Filament resources following project conventions.

## Checklist

**Use TodoWrite to create a todo for EACH item:**

- [ ] Create Resource class with model and navigation properties
- [ ] Create ListRecords page with appropriate concerns/traits
- [ ] Set up query with proper joins if complex relations exist
- [ ] Configure filters using `Filter::make('filters')` wrapper pattern
- [ ] Add `indicateUsing()` for filter indicators
- [ ] Set up `$loggedFilters` array if activity logging needed
- [ ] Use custom column classes where appropriate
- [ ] Add bulk actions if needed
- [ ] Register resource in appropriate panel

## Resource Class Structure

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\{ResourceName}Resource\Pages;
use App\Filament\Tables\Columns\DateTimeColumn;
use App\Filament\Tables\Columns\RemoteOrderColumn;
use App\Filament\Tables\Filters\DateTogglesFilter;
use App\Filament\Tables\Filters\KitUsersFilter;
use App\Filament\Tables\Filters\PlateFilter;
use App\Models\{Model};
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class {ResourceName}Resource extends Resource
{
    protected static ?string $model = {Model}::class;

    protected static ?int $navigationSort = 1;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document';

    // Navigation groups: 'Search', 'Account', 'Admin'
    protected static string|\UnitEnum|null $navigationGroup = 'Search';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Use DateTimeColumn for timestamps
                DateTimeColumn::make('created_at')
                    ->label('Created')
                    ->sortable(),

                // Use RemoteOrderColumn for integration order IDs
                RemoteOrderColumn::make('remote_order_id')
                    ->label('Order Id')
                    ->setIntegrationId(fn ($record) => $record->integration_id),
            ])
            ->filters([
                // CRITICAL: Use single Filter wrapper with columnSpanFull
                Filter::make('filters')
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'lg' => 2])
                    ->schema([
                        // Filter components here
                        PlateFilter::make('scan.ocr')
                            ->label('Search by Plate')
                            ->columnSpanFull(),

                        KitUsersFilter::make('kits')
                            ->label('Kit Users')
                            ->multiple(),
                    ])
                    ->indicateUsing(function (array $data): ?array {
                        $indicators = [];
                        // Build indicators from filter data
                        return $indicators ?: null;
                    })
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['scan']['ocr'] ?? false,
                            fn ($q, $plate) => PlateFilter::modifyQuery(
                                query: $q,
                                column: 'scans.ocr',
                                plate: $plate
                            )
                        )
                    ),

                // DateTogglesFilter for date ranges
                DateTogglesFilter::make('created_at')
                    ->label('Date')
                    ->defaultDate('today')
                    ->dateToggles([
                        'today' => 'Today',
                        'last_7d' => 'Last 7d',
                        'last_30d' => 'Last 30d',
                        'advanced' => 'Advanced',
                    ]),
            ])
            ->query(function (HasTable $livewire) {
                // Use query flattening for complex relations
                return {Model}::withoutGlobalScopes()
                    ->join('related_table', 'related_table.id', '=', 'main_table.related_id')
                    ->select([
                        'main_table.id as id',
                        'main_table.*',
                        'related_table.column as related_column',
                    ])
                    ->whereIntegerInRaw('main_table.kit_id', auth()->user()->getUserKits()->pluck('id'));
            })
            ->defaultSort('created_at', 'desc')
            ->filtersLayout(Tables\Enums\FiltersLayout::Hidden);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\List{ResourceName}::route('/'),
        ];
    }
}
```

## ListRecords Page Structure

```php
<?php

namespace App\Filament\Resources\{ResourceName}Resource\Pages;

use App\Filament\Concerns\HasClickableFilter;
use App\Filament\Concerns\HasFilterActivityLogging;
use App\Filament\Concerns\HasRetryQuery;
use App\Filament\Resources\{ResourceName}Resource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class List{ResourceName} extends ListRecords
{
    // Standard concerns for list pages
    use HasClickableFilter;       // Enables wire:click filter additions
    use HasFilterActivityLogging; // Logs filter usage to activity
    use HasRetryQuery;            // Handles query retry on timeout

    // Define which filters to log
    protected array $loggedFilters = [
        'hotlist.vin' => 'vin',
        'scan.ocr' => 'plateNumber',
    ];

    protected static string $resource = {ResourceName}Resource::class;

    public function getHeading(): string|Htmlable
    {
        return '';
    }
}
```

## Custom Columns Reference

| Column | Use Case |
|--------|----------|
| `DateTimeColumn` | Timestamps with user timezone support |
| `RemoteOrderColumn` | Order IDs with integration link |

## Custom Filters Reference

| Filter | Use Case |
|--------|----------|
| `PlateFilter` | License plate search with FlexSearch support |
| `DateTogglesFilter` | Date range with preset toggles |
| `KitUsersFilter` | Kit user multi-select |
| `AddressFilter` | Geo-location address search |
| `FlexSearchToggle` | Enable fuzzy plate matching |

## Query Flattening Pattern

For N+1 prevention, flatten relations into the main query:

```php
->query(function (HasTable $livewire) {
    return Model::withoutGlobalScopes()
        ->join('table_a', 'table_a.id', '=', 'main.a_id')
        ->join('table_b', 'table_b.id', '=', 'main.b_id')
        ->select([
            'main.id as id',
            'main.*',
            'table_a.name as a_name',
            'table_b.status as b_status',
        ])
        // Scope to user's kits
        ->whereIntegerInRaw('main.kit_id', auth()->user()->getUserKits()->pluck('id'));
})
```

## Feature Flag Integration

For Pro features, add feature flag check:

```php
use App\Filament\Concerns\HasProFeature;

class List{ResourceName} extends ListRecords
{
    use HasProFeature;

    // Or in canAccess():
    public static function canAccess(): bool
    {
        return Feature::active('pro-feature-flag');
    }
}
```

## File Locations

| Type | Location |
|------|----------|
| Resource | `app/Filament/Resources/{Name}Resource.php` |
| ListRecords | `app/Filament/Resources/{Name}Resource/Pages/List{Name}.php` |
| Client Resource | `app/Filament/Client/Resources/{Name}Resource.php` |

## Arguments

$ARGUMENTS
