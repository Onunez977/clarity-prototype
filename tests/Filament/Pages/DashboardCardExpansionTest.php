<?php

namespace Tests\Filament\Pages;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets;
use Illuminate\Support\Carbon;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class DashboardCardExpansionTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_opening_a_card_then_a_different_card_replaces_the_open_card(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        // Opening B while A is open leaves ONLY B expanded (no compounding), and
        // clicking the currently-open card closes it (one scalar key => at most one
        // open region, per the Q8 architecture decision).
        Livewire::test(Dashboard::class)
            ->assertSet('expandedCard', null)
            ->call('toggleCard', 'scan-count')
            ->assertSet('expandedCard', 'scan-count')
            ->call('toggleCard', 'live-hits')
            ->assertSet('expandedCard', 'live-hits')
            ->call('toggleCard', 'live-hits')
            ->assertSet('expandedCard', null);
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_expanded_card_components_never_holds_more_than_one_widget(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $page = new Dashboard;

        $method = new ReflectionMethod($page, 'expandedCardComponents');
        $method->setAccessible(true);

        $page->expandedCard = null;
        $this->assertSame([], $method->invoke($page));

        $page->expandedCard = 'unique-scan-counts';
        $components = $method->invoke($page);
        $this->assertCount(1, $components);
        $this->assertSame(Widgets\Dashboard\UniqueScanCounts::class, $components[0]->getComponent());

        // Switching the open card to a DIFFERENT widget class must fully replace the
        // previous entry, never append to it -- this is the structural (not logic-based)
        // proof that only one detail region can ever exist.
        $page->expandedCard = 'live-hits';
        $components = $method->invoke($page);
        $this->assertCount(1, $components);
        $this->assertSame(Widgets\Dashboard\LiveHits::class, $components[0]->getComponent());

        $page->expandedCard = 'unknown-key';
        $this->assertSame([], $method->invoke($page));
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_expanded_recovery_leader_receives_the_page_date_toggle(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $page = new Dashboard;
        $page->expandedCard = 'recovery-leader';

        $method = new ReflectionMethod($page, 'expandedCardComponents');
        $method->setAccessible(true);

        $page->filters = ['date' => ['date_toggles' => 'last_month']];
        $nested = $method->invoke($page);

        $this->assertCount(1, $nested);
        $this->assertSame(Widgets\Dashboard\RecoveryLeader::class, $nested[0]->getComponent());
        $this->assertSame('last_month', $nested[0]->getData()['date']);

        $page->filters = ['date_toggles' => 'today'];
        $flat = $method->invoke($page);

        $this->assertSame('today', $flat[0]->getData()['date']);

        $page->filters = ['date' => ['date_toggles' => 'last_7d']];
        $last7d = $method->invoke($page);

        $this->assertSame('last_7d', $last7d[0]->getData()['date']);
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_card_keys_match_widget_get_card_key(): void
    {
        // GRID membership, so this reads cards() directly. Do NOT switch it to
        // Dashboard::cardWidgets(): that returns expandableCards(), which omits a locked
        // card -- so gating any card would make this assertion demand the wrong count while
        // the grid itself is unchanged (the Company Metrics foundation).
        $cardWidgets = Widgets\Dashboard\CompanyMetricsOverview::cards();

        // 12 after the DEV-2651 registry cutover absorbed 4 cards into their siblings.
        // Kit Users left the grid for HotlistCount's header row in the Company Metrics foundation.
        $this->assertCount(12, $cardWidgets);

        foreach ($cardWidgets as $key => $class) {
            $this->assertTrue(class_exists($class), "{$class} does not exist");

            // Every card implements getCardKey() now, and its key must match the one it is
            // registered under -- a Stat's toggle key is resolved through this array.
            $this->assertSame($key, (new $class)->getCardKey(), "cardWidgets key mismatch for {$class}");
        }
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_all_four_hit_cards_are_contiguous(): void
    {
        // the dashboard rebuild's constraint, measured where it lives: the four hit cards occupy four
        // ADJACENT SLOTS of the grid array and are never reordered apart.
        //
        // Two things this deliberately does NOT do, both learned in the Company Metrics foundation review:
        //   - it reads cards(), NOT Dashboard::cardWidgets(). cardWidgets() is
        //     expandableCards(), which drops a locked card -- so gating one hit card would
        //     fail this test while the grid stayed perfectly adjacent, blaming a sibling
        //     ticket for a layout violation it did not commit.
        //   - it does not claim the four render as one ROW, and deliberately does not even though
        //     under the operator's order they currently do. Alignment is
        //     `count(preceding built cards) % columns == 0`; four cards precede the block today,
        //     but one insertion ahead of it de-aligns them again. This test measures ARRAY
        //     adjacency only, which holds at every count. See CompanyMetricsOverview::cards().
        $keys = array_keys(Widgets\Dashboard\CompanyMetricsOverview::cards());

        $hitKeys = ['live-hits', 'staging-live-hits', 'historical-hits', 'staging-historical-hits'];
        $positions = array_map(fn (string $key): int|false => array_search($key, $keys, true), $hitKeys);

        $this->assertNotContains(false, $positions, 'every hit card key must exist in cards()');

        sort($positions);

        $this->assertSame(
            range($positions[0], $positions[0] + 3),
            $positions,
            'the four hit cards must occupy four adjacent slots in cards()'
        );
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_get_widgets_returns_the_stats_overview(): void
    {
        $page = new Dashboard;

        $this->assertSame([
            Widgets\Dashboard\CompanyMetricsOverview::class,
        ], $page->getWidgets());
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_company_metrics_cards_are_in_layout_order(): void
    {
        // The 12-card order (DEV-2651 registry cutover, decision 1). Reading down the `lg`
        // 4-column grid:
        //   row 1: Scan Counts | LPR Case Claims | LPR Repossessions | Recovery Counts
        //   row 2: Direct Live Hits | Direct Historical Hits | Staging Live Hits | Staging Historical Hits
        //   row 3: Missed Opportunities | Unique Scans Leaderboard | LPR Recovery Leaderboard | Staging Live Hit Leaderboard
        //
        // See CompanyMetricsOverview::cards() for the three constraints that DO hold.
        $this->assertSame([
            'unique-scan-counts' => Widgets\Dashboard\UniqueScanCounts::class,
            'unique-lpr-case-claims' => Widgets\Dashboard\UniqueLprCaseClaims::class,
            'lpr-repossessions' => Widgets\Dashboard\LprRepossessions::class,
            'recovery-counts' => Widgets\Dashboard\RecoveryCounts::class,
            'live-hits' => Widgets\Dashboard\LiveHits::class,
            'historical-hits' => Widgets\Dashboard\HistoricalHits::class,
            'staging-live-hits' => Widgets\Dashboard\StagingLiveHits::class,
            'staging-historical-hits' => Widgets\Dashboard\StagingHistoricalHits::class,
            'missed-opportunities' => Widgets\Dashboard\MissedOpportunities::class,
            'unique-scans-leaderboard' => Widgets\Dashboard\UniqueScansLeaderboard::class,
            'recovery-leader' => Widgets\Dashboard\RecoveryLeader::class,
            'staging-live-hit-leader' => Widgets\Dashboard\StagingLiveHitLeader::class,
        ], Widgets\Dashboard\CompanyMetricsOverview::cards());

        // Kit Users left the grid entirely -- it is a HotlistCount header stat now.
        $this->assertArrayNotHasKey('kit-users', Widgets\Dashboard\CompanyMetricsOverview::cards());

        $keys = array_keys(Widgets\Dashboard\CompanyMetricsOverview::cards());

        // The three per-kit leaderboards close out the grid. Asked for as "the LAST 3 cards",
        // so it is measured as the final three -- not as adjacency, which a block sitting
        // anywhere in the array would also satisfy.
        $this->assertSame(
            ['unique-scans-leaderboard', 'recovery-leader', 'staging-live-hit-leader'],
            array_slice($keys, -3),
            'the three leaderboards must be the last three cards, in this order'
        );

        // LOAD-BEARING, unlike the rest of the order, and asserted separately from the rule
        // above so it survives that rule changing: UniqueScanCounts FILLS the shared month keys
        // UniqueScansLeaderboard only reads (UniqueScansLeaderboard::chartSeries()), so
        // reversing the two makes the leaderboard's sparkline read 0 on a cold cache.
        $this->assertLessThan(
            array_search('unique-scans-leaderboard', $keys, true),
            array_search('unique-scan-counts', $keys, true),
            'unique-scan-counts must render before unique-scans-leaderboard'
        );
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_dispatching_dashboard_toggle_card_expands_the_matching_chart(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        Livewire::test(Dashboard::class)
            ->assertSet('expandedCard', null)
            ->dispatch('dashboard-toggle-card', key: 'scan-count')
            ->assertSet('expandedCard', 'scan-count')
            ->dispatch('dashboard-toggle-card', key: 'scan-count')
            ->assertSet('expandedCard', null);
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_the_open_card_outline_binds_to_the_page_expanded_card(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $html = Livewire::test(Dashboard::class)->html();

        $this->assertStringContainsString("\$parent.toggleCard('recovery-counts')", $html);
        $this->assertStringContainsString("\$wire.\$parent && \$wire.\$parent.expandedCard === 'recovery-counts'", $html);
        $this->assertStringNotContainsString('data-dashboard-open="true"', $html);
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_kit_status_graph_and_footer_widgets_are_no_longer_registered(): void
    {
        $page = new Dashboard;

        // KitStatusGraph (the donut) is deleted outright by the dashboard rebuild, so this asserts on the
        // registered class NAMES -- a ::class reference to a deleted class would not compile.
        foreach ($page->getWidgets() as $widget) {
            $this->assertStringNotContainsString('KitStatusGraph', $widget);
        }

        $method = new ReflectionMethod($page, 'getFooterWidgets');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke($page));
    }

    /**
     * perf-containment AC-3: the expanded hit-card chart renders two GROUPED (not
     * stacked) datasets. Pre-seeds every month bucket for both cache namespaces
     * so getData() reads cache only -- no ClickHouse call is attempted.
     */
    #[Group('pages')]
    #[Group('dashboard')]
    #[Group('hits')]
    public function test_expanded_hit_chart_renders_two_grouped_datasets_with_distinct_labels(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $orgId = $user->org_id;
        $tz = $user->timezone ?? 'UTC';

        foreach (['live-hits', 'historical-hits', 'staging-live-hits', 'staging-historical-hits'] as $prefix) {
            for ($i = 12; $i >= 0; $i--) {
                $targetMonth = now(tz: $tz)->startOfMonth()->subMonths($i);
                $dateType = $i === 0 ? 'this_month' : $targetMonth->format('Y-m');

                cache()->put("{$prefix}:{$dateType}:org={$orgId}", fake()->numberBetween(1, 50));
                cache()->put("{$prefix}:unique:{$dateType}:org={$orgId}", fake()->numberBetween(1, 50));
            }
        }

        foreach ([
            Widgets\Dashboard\LiveHits::class,
            Widgets\Dashboard\HistoricalHits::class,
            Widgets\Dashboard\StagingLiveHits::class,
            Widgets\Dashboard\StagingHistoricalHits::class,
        ] as $widgetClass) {
            $widget = new $widgetClass;
            $widget->expanded = true;

            $data = $widget->getData();

            $this->assertCount(2, $data['datasets'], "{$widgetClass} must render exactly two datasets");
            $this->assertCount(13, $data['labels'], "{$widgetClass} must have 13 month labels");

            $labels = \array_column($data['datasets'], 'label');
            $this->assertNotSame($labels[0], $labels[1], "{$widgetClass} dataset labels must differ");
            $this->assertTrue(
                \str_contains($labels[0], 'Unique') || \str_contains($labels[1], 'Unique'),
                "{$widgetClass} must have a dataset labelled with 'Unique'"
            );

            foreach ($data['datasets'] as $dataset) {
                // The anti-stacking assertion: makeDataset()'s $stack argument must
                // never be passed, or Chart.js silently stacks the bars (AC-3).
                $this->assertArrayNotHasKey('stack', $dataset, "{$widgetClass} datasets must not be stacked");
                $this->assertCount(13, $dataset['data'], "{$widgetClass} dataset data must align with the 13 labels");
            }
        }
    }

    /**
     * perf-containment AC-4: the legend is only meaningful once the chart is expanded
     * and shows two series. The REGRESSION half proves the trait-only override did not leak
     * onto DashboardWidget base-class consumers that don't use SharedHitStatsData.
     *
     * The exemplar MUST be a card with NO legend logic of its own, or the half stops guarding
     * anything. DEV-2651 t8 replaced the deleted ScanCount with RecoveryCounts, which — in that
     * same ticket — was given its own conditional legend; the assertion then passed only
     * because the fixture is a bare ADMIN whose canViewRatioFigure() is false, i.e. it observed
     * a GATE rather than detecting a leak, and would have stayed green with the legend leaking
     * everywhere. Review-4 R4-C. MissedOpportunities is the correct exemplar: it extends
     * DashboardWidget, does not use SharedHitStatsData, and contains zero references to
     * `legend` or `getOptions`, so its legend comes from the base `$options` default
     * (`display => false`) and can only turn on if something leaked.
     *
     * All three ratio cards now carry conditional legends, so none of them can serve here.
     */
    #[Group('pages')]
    #[Group('dashboard')]
    #[Group('hits')]
    public function test_the_chart_legend_is_enabled_only_on_expanded_hit_widgets(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        foreach ([
            Widgets\Dashboard\LiveHits::class,
            Widgets\Dashboard\HistoricalHits::class,
            Widgets\Dashboard\StagingLiveHits::class,
            Widgets\Dashboard\StagingHistoricalHits::class,
        ] as $widgetClass) {
            $widget = new $widgetClass;
            $method = new ReflectionMethod($widget, 'getOptions');
            $method->setAccessible(true);

            $widget->expanded = false;
            $this->assertFalse(
                $method->invoke($widget)['plugins']['legend']['display'],
                "{$widgetClass} collapsed legend must be off"
            );

            $widget->expanded = true;
            $this->assertTrue(
                $method->invoke($widget)['plugins']['legend']['display'],
                "{$widgetClass} expanded legend must be on"
            );
        }

        foreach ([Widgets\Dashboard\MissedOpportunities::class] as $widgetClass) {
            $widget = new $widgetClass;
            $method = new ReflectionMethod($widget, 'getOptions');
            $method->setAccessible(true);

            $widget->expanded = false;
            $this->assertFalse(
                $method->invoke($widget)['plugins']['legend']['display'],
                "{$widgetClass} collapsed legend must be off"
            );

            $widget->expanded = true;
            $this->assertFalse(
                $method->invoke($widget)['plugins']['legend']['display'],
                "{$widgetClass} legend must stay off even when expanded -- the AC-4 legend must not leak onto {$widgetClass}"
            );
        }
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_admin_can_render_dashboard_with_the_card_grid(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        Livewire::test(Dashboard::class)
            ->assertSuccessful()
            ->assertSee('data-dashboard-card="unique-scan-counts"', false)
            ->assertSee('$parent.toggleCard', false)
            // Review-1 H1: this pinned ScanCount's tooltip, which t8 deleted. The string
            // existed nowhere in app/ any more, so the assertion was unsatisfiable on CI and
            // only looked green locally behind this test's environmental ViewException.
            ->assertSee('data-description="Distinct plates your kits read in this period', false);
    }

    /**
     * Review-1 M2: expanding a card renders the SAME widget class twice on the page -- once
     * as its collapsed card in the grid, once expanded in the detail region. Filament keys
     * widget components "{class}-{arrayIndex}" (vendor Page.php:438), so the single-element
     * expanded array collided with the grid's index-0 key and the two Livewire instances
     * shared a wire:id, which lets an update meant for one render into the other.
     */
    #[Group('pages')]
    #[Group('dashboard')]
    public function test_expanding_a_card_does_not_produce_duplicate_livewire_component_ids(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $html = Livewire::test(Dashboard::class)
            ->call('toggleCard', 'unique-scan-counts')
            ->html();

        preg_match_all('/wire:id="([^"]+)"/', $html, $matches);

        $ids = $matches[1];

        $this->assertNotEmpty($ids, 'expected the rendered dashboard to contain Livewire components');
        $this->assertSame(
            \count(array_unique($ids)),
            \count($ids),
            'every Livewire component on the dashboard must have a distinct wire:id -- a duplicate means the expanded card collides with its own collapsed card in the grid'
        );
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_opening_a_card_scrolls_the_shared_detail_into_view(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $component = Livewire::test(Dashboard::class)
            ->call('toggleCard', 'unique-scan-counts');

        $this->assertStringContainsString(
            'id="'.Dashboard::EXPANDED_DETAIL_ELEMENT_ID.'"',
            $component->html()
        );

        $effects = $component->effects;
        $this->assertArrayHasKey('xjs', $effects);
        $this->assertStringContainsString(Dashboard::EXPANDED_DETAIL_ELEMENT_ID, $effects['xjs'][0]['expression']);
        $this->assertStringContainsString('scrollTo', $effects['xjs'][0]['expression']);

        $component->call('toggleCard', 'live-hits');
        $this->assertStringContainsString('scrollTo', $component->effects['xjs'][0]['expression']);

        $component->call('toggleCard', 'live-hits');
        $this->assertTrue(empty($component->effects['xjs'] ?? null));
        $this->assertStringNotContainsString(
            'id="'.Dashboard::EXPANDED_DETAIL_ELEMENT_ID.'"',
            $component->html()
        );
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_toggling_a_card_does_not_rebuild_overview_stats(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $component = Livewire::test(Widgets\Dashboard\CompanyMetricsOverview::class);
        $instance = $component->instance();
        $cachedStats = new ReflectionProperty($instance, 'cachedStats');
        $cachedStats->setAccessible(true);

        $before = $cachedStats->getValue($instance);
        $this->assertIsArray($before);
        $this->assertNotEmpty($before);

        $this->assertFalse(method_exists($instance, 'syncExpandedCard'));
        $this->assertFalse(method_exists($instance, 'refreshData'));
        $this->assertFalse(property_exists($instance, 'expandedCard'));
    }

    /**
     * The Company Metrics card floor, and the two rules that keep the sparkline from
     * colliding with a description. Three things are asserted and they pull against each
     * other, so none of them describes the layout alone:
     *
     * - min-h-47 (188px) is a deliberate FLOOR. Content with a description ends 116px down,
     *   + 8px clearance + the 64px strip, so every card is the same height with or without
     *   one. It stays a FLOOR: a wrapping label grows the card rather than colliding.
     * - the strip is `static mt-auto` inside a `flex flex-col` card, NOT Filament's
     *   `position: absolute; bottom: 0`. Absolute positioning is what let the strip be drawn
     *   UNDER a description (measured -20px overlap on the four hit cards and the
     *   leaderboard); in flow, the content reserves its own space.
     * - `margin-bottom: -1.5rem` is what stops mt-auto re-inflating the card the way the old
     *   in-flow 8rem sparkline did: it cancels the card's own 24px pad so the 64px strip
     *   contributes only 40px of flow height.
     *
     * An earlier revision of this test asserted `mt-auto` must stay ABSENT. That was correct
     * for the absolute-positioned design it was written against and is not correct now --
     * mt-auto is the fix, and the negative bottom margin is the guard that made it safe.
     */
    #[Group('pages')]
    #[Group('dashboard')]
    public function test_theme_matches_stat_height_to_the_top_row_and_sizes_the_expanded_chart(): void
    {
        $css = file_get_contents(resource_path('css/filament/app/theme.css'));

        $this->assertIsString($css);
        $this->assertStringContainsString('#dashboard-expanded-detail .fi-wi-chart-canvas-ctn', $css);
        $this->assertStringContainsString('height: 600px', $css);

        // The strip is in flow and bottom-anchored, and cannot re-inflate the card.
        $this->assertMatchesRegularExpression(
            '/\.big-stat-chart\s+\.fi-wi-stats-overview-stat-chart\s*\{[^}]*static[^}]*mt-auto/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.big-stat-chart\s+\.fi-wi-stats-overview-stat-chart\s*\{[^}]*margin-bottom:\s*-1\.5rem/',
            $css
        );
        // mt-auto only resolves against a flex column -- without this the strip stops
        // sitting on the card's bottom edge at all.
        $this->assertMatchesRegularExpression('/\.big-stat-chart\s*\{[^}]*flex-col/', $css);

        // SCOPING. The height floor and the 4rem sparkline belong to the Company Metrics
        // grid ONLY. `.big-stat-chart` comes solely from RendersDashboardStat, so anchoring
        // both rules to it is what keeps the other ten StatsOverviewWidget consumers -- and
        // the /client panel, which shares this theme -- out of the change.
        $this->assertMatchesRegularExpression('/\.big-stat-chart\s*\{[^}]*min-h-47/', $css);
        $this->assertMatchesRegularExpression(
            '/\.big-stat-chart\s+\.fi-wi-stats-overview-stat-chart\s+svg\s*\{[^}]*h-16/',
            $css
        );

        // A bare min-h-47 would leak the floor onto every stat card in the app.
        $this->assertDoesNotMatchRegularExpression(
            '/(^|\})\s*\.fi-wi-stats-overview-stat\s*\{[^}]*min-h-/',
            $css
        );

        // And the other consumers keep the layout the published view used to inline.
        $this->assertStringContainsString('.fi-wi-stats-overview-stat:not(.big-stat-chart)', $css);
    }
}
