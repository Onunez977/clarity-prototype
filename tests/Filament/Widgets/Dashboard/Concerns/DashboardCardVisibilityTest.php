<?php

namespace Tests\Filament\Widgets\Dashboard\Concerns;

use App\Enums\FeatureEnum;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Dashboard\CompanyMetricsOverview;
use App\Filament\Widgets\Dashboard\Concerns\DashboardMetricCard;
use App\Filament\Widgets\Dashboard\Concerns\RendersDashboardStat;
use App\Filament\Widgets\Dashboard\KitUsers;
use App\Filament\Widgets\Dashboard\LiveHits;
use App\Filament\Widgets\Dashboard\MissedOpportunities;
use Filament\Widgets\StatsOverviewWidget\Stat;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The Pro-gate visibility seam (the Company Metrics foundation).
 *
 * CompanyMetricsOverview builds its cards as plain data adapters and never Livewire-mounts
 * them, so a card's own canView()/mount() guard does not run on the compact grid path. That
 * is why the seam exists: canViewCardData() decides whether the card's VALUE is produced at
 * all, and expandableCards() decides whether it can be opened.
 *
 * NO production card is gated any more -- every Pro override was removed and
 * FeatureEnum::COMPANY_METRICS deleted with them. The seam itself is deliberately KEPT and
 * still exercised here with local stub cards: it remains live for a role-less viewer, and it
 * is what a future gate would be built on. The allowlist below is now empty, which is the
 * point -- re-gating a card has to be a conscious edit to this file.
 */
class DashboardCardVisibilityTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset here rather than at the end of the test body: an assertion failure mid-test
        // would otherwise leak the flag into every later test in this file.
        GateFollowsCanViewStubCard::$visible = true;

        parent::tearDown();
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_no_production_card_is_withheld_from_a_non_entitled_viewer(): void
    {
        // A plain ADMIN with NO Pro entitlement -- this is the non-entitled case, and the
        // guest short-circuit is deliberately not what is being exercised.
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        // The allowlist is now EMPTY, and that is the assertion. Every Pro gate on this grid
        // was removed and FeatureEnum::COMPANY_METRICS deleted with them; missed-opportunities
        // was the last one (it alone sat on MISSED_OPPORTUNITY rather than COMPANY_METRICS,
        // and its canView() delegated to canViewCardData(), so BOTH overrides had to go
        // together or the pair would recurse rather than deny).
        //
        // Keep this an explicit allowlist rather than a "whatever cards() says" tautology.
        // Its job is unchanged and now points the other way: re-gating a card must be a
        // deliberate, reviewed act, so adding one here has to be a conscious edit.
        $expectedGated = [];

        foreach (CompanyMetricsOverview::cards() as $key => $class) {
            if (\in_array($key, $expectedGated, true)) {
                $this->assertFalse(
                    $class::canViewCardData(),
                    "{$key} is expected to be gated for a non-entitled viewer, but is visible"
                );

                continue;
            }

            $this->assertTrue(
                $class::canViewCardData(),
                "{$key} is gated, but only [".\implode(', ', $expectedGated).'] should be'
            );
        }

        // expandableCards() drops a locked card, so it is now cards() MINUS the gated ones.
        // Asserting on keys (not the whole map) keeps the failure message readable.
        $this->assertSame(
            \array_keys(\array_diff_key(CompanyMetricsOverview::cards(), \array_flip($expectedGated))),
            \array_keys(CompanyMetricsOverview::expandableCards()),
            'expandableCards() must be cards() minus the gated card(s)'
        );
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_every_card_is_expandable_for_an_entitled_viewer(): void
    {
        // The other half of the pair above, and what preserves the ORIGINAL invariant this
        // test file was written to protect: once the entitlement is granted, nothing is
        // withheld and expandableCards() is exactly cards() again. Without this, a bug that
        // gated every card unconditionally would still satisfy the allowlist test above.
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->makeUserPro($user);
        $this->actingAs($user);

        foreach (CompanyMetricsOverview::cards() as $key => $class) {
            $this->assertTrue(
                $class::canViewCardData(),
                "{$key} must be visible to a fully entitled viewer"
            );
        }

        $this->assertSame(
            CompanyMetricsOverview::cards(),
            CompanyMetricsOverview::expandableCards(),
            'with the entitlement granted, expandableCards() must equal cards()'
        );
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_can_view_card_data_defaults_to_the_cards_own_can_view(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        // The default is static::canView(), NOT a bare `true`. Cards are built as adapters and
        // never Livewire-mounted, so canView() is otherwise unenforced on the compact path --
        // a card arriving with its own gate (a Pennant check, say) would silently render its
        // value. Deferring to canView() is a no-op for the 7 ungated cards and closes that hole.
        $this->assertTrue(GateFollowsCanViewStubCard::canViewCardData());

        GateFollowsCanViewStubCard::$visible = false;

        $this->assertFalse(
            GateFollowsCanViewStubCard::canViewCardData(),
            'canViewCardData() must follow canView() by default'
        );

    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_a_gated_card_keeps_its_grid_slot_but_leaves_the_expandable_set(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        // Drives the REAL production methods through a subclass that substitutes cards().
        // An earlier version of this test re-implemented the array_filter inline, which meant
        // expandableCards() could have been replaced with `return cards();` and the test
        // still passed. Caught in the Company Metrics foundation review by all three lanes.
        $this->assertSame(
            ['unique-scan-counts', 'gated-stub', 'live-hits'],
            \array_keys(StubOverview::cards()),
            'a gated card keeps its grid slot, in its original position'
        );

        $this->assertSame(
            ['unique-scan-counts', 'live-hits'],
            \array_keys(StubOverview::expandableCards()),
            'but it is absent from the expandable set'
        );

        $this->assertArrayNotHasKey('gated-stub', StubOverview::expandableCards());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_the_page_resolves_toggle_keys_through_the_expandable_set_only(): void
    {
        // R2 -- "the third surviving mutation", found by all three round-3 lanes.
        // Dashboard::cardWidgets() is the ONLY thing that makes a locked card
        // non-expandable: toggleCard() is deliberately unguarded, and Filament's own widget
        // gate filters canView(), not canViewCardData().
        //
        // This drives the REAL Dashboard::cardWidgets(). An earlier attempt used a Dashboard
        // subclass that OVERRODE cardWidgets(), so the production line never ran and the
        // assertion reduced to X === X -- the round-2 tautology moved up one level, caught
        // in round 4. No seam is needed: canViewCardData() defaults to the card's own
        // canView() (hasAnyRole ADMIN|USER), so a viewer holding NEITHER role makes every
        // card non-viewable -- including missed-opportunities, whose own gate carries the
        // same parent::canView() role term (DEV-2462; auth()->check() alone would NOT
        // exclude this user, since they ARE authenticated). cards() still returns 12;
        // expandableCards() returns 0, and it stays 0 even if the org is Pro.
        // Reverting line 99 to ::cards() therefore fails right here.
        $this->actingAs($this->createUser(permissions: []));

        $page = new Dashboard;

        $method = new ReflectionMethod($page, 'cardWidgets');
        $method->setAccessible(true);

        $this->assertCount(
            0,
            $method->invoke($page),
            'cardWidgets() must resolve through expandableCards(): a viewer who may see no '
            .'card value must be offered nothing to expand'
        );

        // ...while the GRID keeps every slot. This pair is what makes the two arrays
        // genuinely different, so the mutation cannot hide behind them being equal.
        $this->assertCount(12, CompanyMetricsOverview::cards());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_the_expanded_region_is_empty_for_a_locked_card(): void
    {
        // The shape that matters: canView() TRUE, canViewCardData() FALSE. Filament's own
        // widget gate (Page::getWidgetsSchemaComponents()) filters on canView() only, so for
        // this shape it does nothing and expandedCardComponents()'s lookup is the only
        // defence. A role-less viewer would make canView() false and let Filament empty the
        // collection regardless, which is why this acts as an admin.
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $page = new DashboardWithGatedCard;
        $page->expandedCard = 'gated-stub';

        $method = new ReflectionMethod($page, 'expandedCardComponents');
        $method->setAccessible(true);

        $this->assertSame(
            [],
            $method->invoke($page),
            'a card the viewer may SEE but whose value is withheld must still not expand'
        );

        // Assert WHICH class resolved, not just that one did. Counting alone leaves a
        // mutation alive: reading CompanyMetricsOverview::cards() directly instead of
        // $this->cardWidgets() also yields exactly one component here. Naming the stub
        // kills it, because cards()['unique-scan-counts'] is the real UniqueScanCounts.
        $page->expandedCard = 'unique-scan-counts';
        $components = $method->invoke($page);

        $this->assertCount(1, $components);
        $this->assertSame(
            UngatedStubCard::class,
            $components[0]->getComponent(),
            'the resolved widget must come from cardWidgets(), not from cards() directly'
        );
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_locked_and_unlocked_renderings_share_one_livewire_key(): void
    {
        // R6: dashboardStatKey() was extracted so the two renderings "can never diverge".
        // Dropping ->key() from lockedStat() used to survive green.
        // Read the protected property rather than getKey(): Stat extends a Filament
        // schema Component whose getKey() dereferences an uninitialised $container on an
        // unmounted instance.
        $readKey = static function (Stat $stat): ?string {
            $property = new ReflectionProperty($stat, 'key');
            $property->setAccessible(true);

            return $property->isInitialized($stat) ? $property->getValue($stat) : null;
        };

        $this->assertSame('dashboard-stat-gated-stub', $readKey((new GatedStubCard)->lockedStat()));
        $this->assertSame('dashboard-stat-ungated-stub', $readKey((new UngatedStubCard)->toStat()));
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_get_stats_routes_a_gated_card_to_locked_stat_without_computing_it(): void
    {
        // THE declared blast radius of the Company Metrics foundation: getStats() must branch on canViewCardData()
        // BEFORE calling toStat(), because toStat() is what runs the card's ClickHouse query.
        // Reversing those two lines turns the seam into a presentation-only gate and leaks
        // the withheld value. This test is the tripwire for that, driving the real getStats().
        GatedStubCard::$valueWasComputed = false;
        UngatedStubCard::$valueWasComputed = false;

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $overview = new StubOverview;

        $method = new ReflectionMethod($overview, 'getStats');
        $method->setAccessible(true);

        /** @var array<Stat> $stats */
        $stats = $method->invoke($overview);

        // Every card still renders -- the locked one keeps its slot.
        $this->assertCount(3, $stats);

        $this->assertFalse(
            GatedStubCard::$valueWasComputed,
            'getStats() must NOT compute a gated card value -- the gate has to precede toStat()'
        );

        $this->assertTrue(
            UngatedStubCard::$valueWasComputed,
            'an ungated card must still be computed normally'
        );

        $values = \array_map(static fn (Stat $stat): string => (string) $stat->getValue(), $stats);
        $this->assertContains(DashboardMetricCard::LOCKED_VALUE, $values, 'the locked card renders no value');

        $locked = \array_values(\array_filter(
            $stats,
            static fn (Stat $stat): bool => $stat->getValue() === DashboardMetricCard::LOCKED_VALUE,
        ))[0];

        $this->assertSame('true', $locked->getExtraAttributes()['data-dashboard-locked']);
        $this->assertArrayNotHasKey('wire:click', $locked->getExtraAttributes());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_locked_stat_shows_no_value_and_never_computes_the_metric(): void
    {
        GatedStubCard::$valueWasComputed = false;

        $stat = (new GatedStubCard)->lockedStat();

        // THE point of the seam: the withheld metric is not computed, so it cannot leak
        // into the response, a log, or a cache entry. A presentation-only gate would have
        // run the query and then hidden the result.
        $this->assertFalse(
            GatedStubCard::$valueWasComputed,
            'lockedStat() must not compute the card value'
        );

        // Assert the LITERAL as well as the constant. Every other assertion reaches the
        // value symbolically, so changing LOCKED_VALUE to '0' or '999' would have left the
        // whole suite green while a gated org saw a fabricated number in the slot -- the
        // exact thing AC-4's "shows no value" forbids.
        $this->assertSame('—', DashboardMetricCard::LOCKED_VALUE, 'the locked placeholder must not be a number');
        $this->assertSame(DashboardMetricCard::LOCKED_VALUE, $stat->getValue());
        $this->assertNull($stat->getChart(), 'no sparkline: it would leak the series shape');
        $this->assertSame('Gated Stub Card', $stat->getLabel());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_locked_stat_carries_no_click_affordance(): void
    {
        $attributes = (new GatedStubCard)->lockedStat()->getExtraAttributes();

        // A locked card must not be openable even if its key somehow reached toggleCard().
        $this->assertArrayNotHasKey('wire:click', $attributes);
        $this->assertArrayNotHasKey('x-bind:class', $attributes);
        $this->assertArrayNotHasKey('x-bind:data-dashboard-open', $attributes);
        $this->assertArrayNotHasKey('data-description', $attributes);
        $this->assertStringNotContainsString('cursor-pointer', $attributes['class']);

        $this->assertSame('true', $attributes['data-dashboard-locked']);

        // theme.css hangs the grid's height floor off `big-stat-chart`, so a locked card
        // must keep the class or it renders shorter than its neighbours.
        $this->assertStringContainsString('big-stat-chart', $attributes['class']);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_a_locked_card_carries_no_upsell_unless_it_opts_in(): void
    {
        // The upsell seam is OPT-IN. Both hooks default to null on the shared trait, so the
        // 8 sibling cards inherit the MECHANISM without inheriting this card's copy
        // -- a blanket "Upgrade to Pro" would be wrong for a card gated on anything else.
        // Reverting the defaults to a literal would fail here.
        $stat = (new GatedStubCard)->lockedStat();

        $this->assertNull($stat->getDescription(), 'a card that opts out renders the bare placeholder');
        $this->assertNull($stat->getDescriptionIcon());
        $this->assertNull($stat->getUrl(), 'and stays a div, not an anchor');
    }

    /**
     * The locked card's contract, minus the upsell.
     *
     * DEV-2462 added "Upgrade to Pro" plus a Subscription link here at the operator's request.
     * Both were removed with this card's Pro gate: the card can now only lock for a viewer
     * holding neither ADMIN nor USER, and buying Pro would not unlock it, so the copy and the
     * link would have been actively misleading in the one case they could still render.
     *
     * What the gate exists to withhold is unchanged and is what this now pins: the number and
     * the series shape stay withheld, and the card stays un-expandable.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_the_locked_card_withholds_the_value_and_no_longer_offers_an_upsell(): void
    {
        // Driving the real card rather than a stub is the point -- what remains of the locked
        // rendering lives partly on the card and partly on the trait.
        $stat = (new MissedOpportunities)->lockedStat();

        $this->assertNull($stat->getDescription(), 'the "Upgrade to Pro" copy went with the gate');
        $this->assertNull($stat->getUrl(), 'and so did the upgrade.from link');
        $this->assertNull($stat->getDescriptionIcon(), 'the padlock icon is only set alongside the copy');

        // Still withheld -- the half of this test that survives the ungating unchanged.
        $this->assertSame(DashboardMetricCard::LOCKED_VALUE, $stat->getValue());
        $this->assertNull($stat->getChart(), 'no sparkline: it would leak the series shape');

        // ...and it is still not expandable: lockedStatAttributes() strips the toggle.
        $this->assertArrayNotHasKey('wire:click', $stat->getExtraAttributes());
        $this->assertSame('true', $stat->getExtraAttributes()['data-dashboard-locked']);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_the_upsell_does_not_reach_the_cards_value(): void
    {
        // The tripwire that matters, on the stub that can actually observe it: opting in to
        // the upsell must not make lockedStat() touch the metric. GatedStubCard flips
        // $valueWasComputed inside toStat(), so any implementation that routed through it to
        // build the description or the link trips this.
        UpsellingStubCard::$valueWasComputed = false;

        $stat = (new UpsellingStubCard)->lockedStat();

        $this->assertFalse(
            UpsellingStubCard::$valueWasComputed,
            'an upselling locked card must still never compute its value'
        );

        $this->assertSame('Upgrade to Pro', $stat->getDescription());
        $this->assertSame('https://example.test/subscription', $stat->getUrl());
        $this->assertSame(DashboardMetricCard::LOCKED_VALUE, $stat->getValue());
        $this->assertNull($stat->getChart());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_an_ungated_stub_still_renders_its_value_normally(): void
    {
        // Guards against a seam that accidentally locks everything.
        $stat = (new UngatedStubCard)->toStat();

        $this->assertSame('123', $stat->getValue());
        $this->assertArrayHasKey('wire:click', $stat->getExtraAttributes());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_get_card_label_does_not_require_a_get_heading_method(): void
    {
        // getCardLabel() lives on RendersDashboardStat so lockedStat() can be shared, but
        // getHeading() comes from the Filament base class and TableWidget (which KitUsers
        // extends) has none. Unguarded, getCardLabel() would fatal on such a class -- and
        // only once something actually locked it, long after the change that introduced it.
        $this->assertSame(
            'Gated Stub Card',
            (new GatedStubCard)->getCardLabel(),
            'a card WITH getHeading() must use it'
        );
        $this->assertSame(
            'HeadinglessStubCard',
            (new HeadinglessStubCard)->getCardLabel(),
            'a card with no getHeading() AND no $heading falls back to its class basename, not a fatal'
        );

        // The real class the guard was written for. KitUsers extends TableWidget (no
        // getHeading()) but DOES set a static $heading, reachable via getTableHeading() --
        // so it must resolve its real label, not the 'KitUsers' basename.
        $this->assertSame('Active Kit Users', (new KitUsers)->getCardLabel());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_can_view_card_data_is_total_with_no_authenticated_user(): void
    {
        // No actingAs. Dashboard::cardWidgets() -> expandableCards() -> canViewCardData() is
        // reachable from unauthenticated contexts, so the default must not dereference
        // auth()->user(). Regression guard: an earlier version of this default called
        // static::canView() unguarded and errored with "hasAnyRole() on null".
        $this->assertTrue(auth()->guest());

        $this->assertTrue(LiveHits::canViewCardData());

        // With every Pro gate removed, no card carries its own auth()->check()-guarded
        // override any more, so the guest short-circuit in the default answers true for all
        // of them and expandableCards() -- an array_filter -- drops NOTHING. The property
        // this test exists for is unchanged and is what the assertion still proves: the call
        // is TOTAL with no authenticated user. It used to drop exactly the five gated keys.
        $this->assertSame(
            \array_keys(CompanyMetricsOverview::cards()),
            \array_keys(CompanyMetricsOverview::expandableCards()),
            'expandableCards() must be callable without an authenticated user, and with no '
            .'card gated it must drop nothing'
        );
    }
}

/**
 * A card whose data is withheld. `$valueWasComputed` is the tripwire proving lockedStat()
 * never reaches the value.
 */
class GatedStubCard implements DashboardMetricCard
{
    use RendersDashboardStat;

    public static bool $valueWasComputed = false;

    public function getCardKey(): string
    {
        return 'gated-stub';
    }

    public function getHeading(): string
    {
        return 'Gated Stub Card';
    }

    public static function canView(): bool
    {
        return true;
    }

    public static function canViewCardData(): bool
    {
        return false;
    }

    public function toStat(?string $selectedCard = null): Stat
    {
        self::$valueWasComputed = true;

        return $this->dashboardStat(Stat::make($this->getCardLabel(), '999'), $selectedCard);
    }
}

/** The control: ungated, so it behaves exactly as it did before the seam existed. */
class UngatedStubCard implements DashboardMetricCard
{
    use RendersDashboardStat;

    public static bool $valueWasComputed = false;

    public function getCardKey(): string
    {
        return 'ungated-stub';
    }

    public function getHeading(): string
    {
        return 'Ungated Stub Card';
    }

    public static function canView(): bool
    {
        return true;
    }

    public function toStat(?string $selectedCard = null): Stat
    {
        self::$valueWasComputed = true;

        return $this->dashboardStat(Stat::make($this->getCardLabel(), '123'), $selectedCard);
    }
}

/** Proves canViewCardData()'s default tracks canView(). */
class GateFollowsCanViewStubCard implements DashboardMetricCard
{
    use RendersDashboardStat;

    public static bool $visible = true;

    public function getCardKey(): string
    {
        return 'gate-follows-stub';
    }

    public function getHeading(): string
    {
        return 'Gate Follows Stub Card';
    }

    public static function canView(): bool
    {
        return self::$visible;
    }

    public function toStat(?string $selectedCard = null): Stat
    {
        return $this->dashboardStat(Stat::make($this->getCardLabel(), '1'), $selectedCard);
    }
}

/**
 * Substitutes cards() so the seam is exercised against the REAL expandableCards() and
 * getStats(). Possible only because both use `static::cards()` rather than `self::cards()`.
 */
class StubOverview extends CompanyMetricsOverview
{
    public static function cards(): array
    {
        // All stubs on purpose. An earlier version used the real LiveHits here, whose leg
        // asserted nothing (its fetch paths swallow and degrade to zeros under `testing`)
        // while adding an environment dependency.
        return [
            'unique-scan-counts' => UngatedStubCard::class,
            'gated-stub' => GatedStubCard::class,
            'live-hits' => SecondUngatedStubCard::class,
        ];
    }
}

/**
 * A Dashboard whose card map is StubOverview's, so expandedCardComponents() can be driven
 * against a card that is VISIBLE (canView() true) but value-withheld (canViewCardData()
 * false) -- the one shape Filament's native canView() filter does not cover.
 *
 * Only the collaborator is substituted; expandedCardComponents() itself is the real thing.
 */
class DashboardWithGatedCard extends Dashboard
{
    protected function cardWidgets(): array
    {
        return StubOverview::expandableCards();
    }
}

/** A second ungated control, so StubOverview needs no production card. */
class SecondUngatedStubCard implements DashboardMetricCard
{
    use RendersDashboardStat;

    public function getCardKey(): string
    {
        return 'second-ungated-stub';
    }

    public function getHeading(): string
    {
        return 'Second Ungated Stub Card';
    }

    public static function canView(): bool
    {
        return true;
    }

    public function toStat(?string $selectedCard = null): Stat
    {
        return $this->dashboardStat(Stat::make($this->getCardLabel(), '7'), $selectedCard);
    }
}

/** A gated card that OPTS IN to the upsell seam, so the opt-in path has a query tripwire. */
class UpsellingStubCard implements DashboardMetricCard
{
    use RendersDashboardStat;

    public static bool $valueWasComputed = false;

    public function getCardKey(): string
    {
        return 'upselling-stub';
    }

    public function getHeading(): string
    {
        return 'Upselling Stub Card';
    }

    public static function canView(): bool
    {
        return true;
    }

    public static function canViewCardData(): bool
    {
        return false;
    }

    protected function lockedStatDescription(): ?string
    {
        return 'Upgrade to Pro';
    }

    protected function lockedStatUrl(): ?string
    {
        return 'https://example.test/subscription';
    }

    public function toStat(?string $selectedCard = null): Stat
    {
        self::$valueWasComputed = true;

        return $this->dashboardStat(Stat::make($this->getCardLabel(), '42'), $selectedCard);
    }
}

/** Stands in for KitUsers: uses the trait but has no getHeading(). */
class HeadinglessStubCard implements DashboardMetricCard
{
    use RendersDashboardStat;

    public function getCardKey(): string
    {
        return 'headingless-stub';
    }

    public static function canView(): bool
    {
        return true;
    }

    public function toStat(?string $selectedCard = null): Stat
    {
        return $this->dashboardStat(Stat::make($this->getCardLabel(), '1'), $selectedCard);
    }
}
