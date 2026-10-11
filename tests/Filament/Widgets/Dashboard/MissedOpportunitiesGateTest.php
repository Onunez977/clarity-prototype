<?php

namespace Tests\Filament\Widgets\Dashboard;

use App\Filament\Widgets\Dashboard\CompanyMetricsOverview;
use App\Filament\Widgets\Dashboard\Concerns\DashboardMetricCard;
use App\Filament\Widgets\Dashboard\Concerns\StatHelper;
use App\Filament\Widgets\Dashboard\MissedOpportunities;
use Filament\Widgets\StatsOverviewWidget\Stat;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Deliberately a SIBLING of MissedOpportunitiesTest, and deliberately WITHOUT
 * `RequiresClickhouse`.
 *
 * That trait is applied at class level on MissedOpportunitiesTest, so every test in it skips
 * unless CLICKHOUSE_DRIVER=clickhouse -- which means locally they do not run at all and a
 * broken assertion only surfaces in CI (DEV-2448 records this exact trap). None of the
 * guarantees below touch ClickHouse: they are pure gate and locked-card checks. Keeping them
 * here means they run everywhere, always.
 *
 * What they pin: this card was the FIRST genuinely Pro-gated card (DEV-2462) and the LAST one
 * still gated. Its gate is now gone, along with every other card's on this grid. It alone sat
 * on MISSED_OPPORTUNITY rather than COMPANY_METRICS, and its canView() delegated to
 * canViewCardData(), so BOTH overrides had to be removed together -- removing one would have
 * recursed rather than denied.
 *
 * MISSED_OPPORTUNITY itself is untouched and still gates twelve other surfaces (the policy,
 * the client widgets, the resource page, activity, notifications, alert settings). Only this
 * dashboard card stopped consulting it.
 */
class MissedOpportunitiesGateTest extends TestCase
{
    use StatHelper;

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('missed-opportunities')]
    public function test_can_view_card_data_needs_no_entitlement(): void
    {
        // Guest: the call must stay TOTAL, and the default short-circuits to true.
        $this->assertTrue(auth()->guest());
        $this->assertTrue(MissedOpportunities::canViewCardData());

        // A non-Pro ADMIN -- the case that used to be denied and shown an upsell.
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));
        $this->assertTrue(MissedOpportunities::canViewCardData());

        // And a Pro org, which is simply no longer a distinguishing factor.
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));
        $this->assertTrue(MissedOpportunities::canViewCardData());
    }

    /**
     * The surviving withholding path, and why canViewCardData()/lockedStat() were KEPT rather
     * than deleted along with the gates: the ADMIN/USER role check is now the only thing that
     * can withhold this card.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('missed-opportunities')]
    public function test_a_viewer_holding_no_role_is_still_denied(): void
    {
        $this->actingAs($this->createUser(permissions: [], pro: true));

        $this->assertTrue(auth()->check(), 'the viewer IS authenticated -- auth()->check() alone cannot exclude them');
        $this->assertFalse(
            MissedOpportunities::canViewCardData(),
            'a viewer with neither ADMIN nor USER must still be denied the value'
        );
        $this->assertArrayNotHasKey(
            'missed-opportunities',
            CompanyMetricsOverview::expandableCards(),
            'and must not be offered it as an expandable card'
        );
    }

    /**
     * The upsell went with the gate. Buying Pro no longer unlocks this card, so "Upgrade to
     * Pro" and its link would have been actively misleading in the one case they could still
     * render -- a role-less viewer.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('missed-opportunities')]
    public function test_the_locked_card_no_longer_advertises_a_pro_upgrade(): void
    {
        $this->actingAs($this->createUser(permissions: [], pro: true));

        $locked = (new MissedOpportunities)->lockedStat();

        $this->assertSame(DashboardMetricCard::LOCKED_VALUE, $locked->getValue());
        $this->assertNull($locked->getDescription(), 'no "Upgrade to Pro" copy -- Pro does not unlock this card any more');
        $this->assertNull($locked->getUrl(), 'and no upgrade link');
    }

    /**
     * Drives the REAL `CompanyMetricsOverview::getStats()` rather than calling `lockedStat()`
     * directly, which on its own is close to a tautology (it takes no value argument by
     * construction). The mutation this kills: routing a withheld card to `toStat()` instead of
     * `lockedStat()` in `getStats()`, which would serve the poisoned cache value.
     *
     * Two things changed when the Pro gate was removed, and the second is worth knowing:
     *
     * 1. The viewer had to be re-pointed from a non-Pro ADMIN to a ROLE-LESS one. The old framing
     *    asserted `canViewCardData()` was false for a non-Pro ADMIN, which is now true, so it
     *    failed in CI as "true is false" (and only in CI — see this class's docblock).
     * 2. **The locked path is no longer reachable through the rendered grid.** With nothing
     *    Pro-gated, any viewer who can load `CompanyMetricsOverview` sees every card, and the only
     *    viewer who withholds one — a role-less user — is refused the widget itself with a 403.
     *    So this drives `getStats()` directly. The seam is still live and still worth pinning; it
     *    just no longer has an end-to-end rendering that exercises it.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('missed-opportunities')]
    public function test_get_stats_routes_a_withheld_card_to_locked_stat_and_never_leaks_its_value(): void
    {
        $this->actingAs($this->createUser(permissions: []));

        $orgId = auth()->user()->org_id;

        // Poison every bucket the card could read. If the withholding leaks, one surfaces.
        foreach (['today', 'last_7d', 'this_month', 'last_month'] as $key) {
            cache()->put($this->cacheKey(
                key: $key,
                orgId: $orgId,
                class: MissedOpportunities::class,
            ), 987654);
        }

        $this->assertFalse(MissedOpportunities::canViewCardData());
        $this->assertArrayNotHasKey(
            'missed-opportunities',
            CompanyMetricsOverview::expandableCards(),
            'a locked card must not be expandable'
        );
        $this->assertArrayHasKey(
            'missed-opportunities',
            CompanyMetricsOverview::cards(),
            'a locked card must KEEP its grid slot'
        );

        $method = new \ReflectionMethod(CompanyMetricsOverview::class, 'getStats');
        $method->setAccessible(true);

        /** @var array<Stat> $stats */
        $stats = $method->invoke(new CompanyMetricsOverview);

        $this->assertCount(\count(CompanyMetricsOverview::cards()), $stats, 'a withheld card keeps its slot');

        foreach ($stats as $stat) {
            $this->assertSame(
                DashboardMetricCard::LOCKED_VALUE,
                $stat->getValue(),
                'a role-less viewer withholds EVERY card, so getStats() must route them all to lockedStat()'
            );
            $this->assertStringNotContainsString('987654', (string) $stat->getValue(), 'the poisoned cache must never surface');
        }
    }
}
