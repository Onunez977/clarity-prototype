<?php

namespace Tests\Filament\Widgets\Dashboard;

use App\Filament\Widgets\Dashboard\Concerns\StatHelper;
use App\Filament\Widgets\Dashboard\StagingHistoricalHits;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class StagingHistoricalHitsTest extends TestCase
{
    use StatHelper;

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('hits')]
    public function test_can_render(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        Livewire::test(StagingHistoricalHits::class)
            ->assertSuccessful()
            ->assertSeeText('Staging Historical Hits');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('hits')]
    public function test_cannot_render_without_permission(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        Livewire::test(StagingHistoricalHits::class)
            ->assertForbidden();
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('hits')]
    public function test_can_render_stats(): void
    {
        $this->travelTo(now()->setTimezone('America/Chicago')->startOfDay()->addHours(12));

        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        // Distinct sentinels for the total and unique cache keys so a future
        // cross-wiring regression (description reading the wrong series) fails
        // loudly instead of silently passing.
        $totalCount = fake()->numberBetween(1, 100);
        $uniqueCount = fake()->numberBetween(101, 200);

        cache()->put($this->cacheKey(
            key: 'today',
            orgId: $user->org_id,
            class: StagingHistoricalHits::class,
        ), $totalCount);

        cache()->put($this->cacheKey(
            key: 'unique:today',
            orgId: $user->org_id,
            class: StagingHistoricalHits::class,
        ), $uniqueCount);

        Livewire::test(StagingHistoricalHits::class, ['date' => 'today', 'expanded' => true])
            ->assertSuccessful()
            ->dispatch('refresh-dashboard', 'today')
            ->assertSeeText('Today: '.number_format($uniqueCount));

        // The compact Company Metrics card (toStat(), not the standalone chart
        // above) surfaces the total as a secondary line -- assert it with the
        // OTHER sentinel so a future cross-wiring regression fails loudly.
        $this->seedMonthlyCacheBuckets($user->org_id, $user->timezone ?? 'UTC');

        $widget = new StagingHistoricalHits;
        $widget->date = 'today';

        $this->assertSame(
            number_format($totalCount).' total hits',
            $widget->toStat()->getDescription()
        );
    }

    private function seedMonthlyCacheBuckets(int $orgId, string $timezone): void
    {
        // resolvePastMonths()/resolvePastUniqueMonths() gate on ALL FOUR hit-widget
        // prefixes being cached for a given month before trusting the cache -- an
        // incompletely-seeded month is treated as uncached and triggers a live batch
        // query that overwrites every prefix.
        for ($i = 12; $i >= 0; $i--) {
            $targetMonth = now(tz: $timezone)->startOfMonth()->subMonths($i);
            $dateType = $i === 0 ? 'this_month' : $targetMonth->format('Y-m');

            foreach (['live-hits', 'historical-hits', 'staging-live-hits', 'staging-historical-hits'] as $prefix) {
                cache()->put("{$prefix}:{$dateType}:org={$orgId}", fake()->numberBetween(1, 50));
                cache()->put("{$prefix}:unique:{$dateType}:org={$orgId}", fake()->numberBetween(1, 50));
            }
        }
    }
}
