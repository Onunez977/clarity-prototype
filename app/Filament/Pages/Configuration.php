<?php

namespace App\Filament\Pages;

use App\Facades\Analytics;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use InsightLPR\BlackAdam\Enum\RoleEnum;

class Configuration extends Page
{
    protected static ?int $navigationSort = 4;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|\UnitEnum|null $navigationGroup = 'Account';

    protected static ?string $navigationLabel = 'Configuration';

    protected static ?string $title = 'Configuration';

    protected static ?string $slug = 'configuration';

    protected string $view = 'filament.pages.configuration';

    /**
     * Values keyed by org_meta.properties. Prototype only — nothing here calls Clear Plan, RDN, or a lender.
     *
     * @var array<string, bool>
     */
    public array $values = [];

    /**
     * Add a group by appending one entry. Add a toggle by appending one option.
     * `key` is the org_meta.properties field. The RDN key is shared with Manage Subscription.
     *
     * @return list<array{heading: string, description?: string, options: list<array{key: string, label: string, helper: string, default?: bool, activity?: string}>}>
     */
    public static function sections(): array
    {
        return [
            [
                'heading' => 'Clear Plan automated hit integration',
                'options' => [
                    [
                        'key' => 'prototype_clearplan_hit_integration',
                        'label' => 'Clear Plan automated hit integration',
                        'helper' => 'Mark qualifying hits for Clear Plan. This prototype stores the choice only — nothing is sent.',
                    ],
                ],
            ],
            [
                'heading' => 'RDN Statistics Push',
                'options' => [
                    [
                        'key' => 'platform_repo_pro_rdn_metrics_push',
                        'label' => 'RDN Statistics Push',
                        'helper' => 'Push hourly scan and hit counts to RDN for your dashboards. Disabled by default.',
                        'activity' => 'rdn_metrics_toggle',
                    ],
                ],
            ],
            [
                'heading' => 'Hits logic',
                'description' => 'Rules applied when deciding whether a read becomes a hit.',
                'options' => [
                    [
                        'key' => 'prototype_hits_plate_state_match',
                        'label' => 'Plate & State must match',
                        'helper' => 'Count a hit only when the plate and the state both match.',
                    ],
                    [
                        'key' => 'prototype_hits_fuzzy',
                        'label' => 'Use fuzzy logic',
                        'helper' => 'Treat a close plate read as a match.',
                    ],
                    [
                        'key' => 'prototype_hits_exclude_moving',
                        'label' => 'Exclude moving hits',
                        'helper' => 'Ignore hits captured while the camera vehicle was moving.',
                    ],
                    [
                        'key' => 'prototype_hits_exclude_ymm',
                        'label' => 'Exclude YMM (year/make/model) mismatches',
                        'helper' => 'Ignore hits whose year, make, or model does not match the assignment.',
                    ],
                ],
            ],
            [
                'heading' => 'Lender sharing',
                'options' => [
                    [
                        'key' => 'prototype_lender_sharing',
                        'label' => 'Lender sharing',
                        'helper' => 'Share hit details with the lienholder on the account. This prototype stores the choice only — nothing is shared.',
                    ],
                ],
            ],
        ];
    }

    public function mount(): void
    {
        $properties = auth()->user()->org?->meta?->properties ?? [];

        foreach (self::sections() as $section) {
            foreach ($section['options'] as $option) {
                $this->values[$option['key']] = (bool) ($properties[$option['key']] ?? ($option['default'] ?? false));
            }
        }

        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'configuration');
    }

    public function getHeading(): string|Htmlable
    {
        return 'Configuration';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Organization settings for this account. Each change saves immediately.';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole(RoleEnum::ADMIN->value);
    }

    public function toggle(string $key): void
    {
        if (! auth()->user()->hasRole(RoleEnum::ADMIN->value)) {
            Notification::make()
                ->title('Unable to update setting')
                ->body('You do not have permission to change this setting.')
                ->danger()
                ->send();

            return;
        }

        $option = $this->option($key);

        if ($option === null) {
            return;
        }

        $org = auth()->user()->org;
        $meta = $org->meta ?? $org->meta()->create(['properties' => []]);
        $value = ! (bool) ($meta->properties[$key] ?? ($option['default'] ?? false));

        $meta->updateProperties([
            $key => $value,
        ]);

        if (! empty($option['activity'])) {
            activity($option['activity'])
                ->performedOn($meta)
                ->causedBy(auth()->user())
                ->withProperties([
                    'org_id' => $org->id,
                    'old' => ! $value,
                    'new' => $value,
                ])
                ->log($value ? 'enabled' : 'disabled');
        }

        $this->values[$key] = $value;

        Notification::make()
            ->title($option['label'].($value ? ' enabled' : ' disabled'))
            ->success()
            ->send();
    }

    /**
     * @return array{key: string, label: string, helper: string, default?: bool, activity?: string}|null
     */
    public function option(string $key): ?array
    {
        foreach (self::sections() as $section) {
            foreach ($section['options'] as $option) {
                if ($option['key'] === $key) {
                    return $option;
                }
            }
        }

        return null;
    }
}
