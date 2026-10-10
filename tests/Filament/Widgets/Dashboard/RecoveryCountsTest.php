<?php

namespace Tests\Filament\Widgets\Dashboard;

use App\Clickhouse\Queries\RecoveryTotalsBatchQuery;
use App\Filament\Widgets\Dashboard\Concerns\StatHelper;
use App\Filament\Widgets\Dashboard\RecoveryCounts;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class RecoveryCountsTest extends TestCase
{
    use StatHelper;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_the_card_and_dataset_labels_are_both_relabelled(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $widget = new RecoveryCounts;

        $this->assertSame('Total Repossessions', $widget->getCardLabel());
        $this->assertSame('Total Repossessions', $widget->getDatasetLabel());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_can_render(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);
        $this->seedRepoMonths($user->org_id);

        Livewire::test(RecoveryCounts::class)
            ->assertSuccessful()
            ->assertSeeText('Repossessions');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_cannot_render_without_permission(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        Livewire::test(RecoveryCounts::class)
            ->assertForbidden()
            ->assertDontSeeText('Repossessions');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_can_render_stats(): void
    {
        $this->travelTo(now()->setTimezone('America/Chicago')->startOfDay()->addHours(12));

        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);
        $this->seedRepoMonths($user->org_id);

        $total = fake()->numberBetween(1, 100);
        cache()->put($this->cacheKey(
            key: 'today',
            orgId: $user->org_id,
            class: RecoveryCounts::class,
        ), $total);

        Livewire::test(RecoveryCounts::class, ['date' => 'today', 'expanded' => true])
            ->assertSuccessful()
            ->dispatch('refresh-dashboard', 'today')
            ->assertSeeText('Today: '.number_format($total));
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_can_switch_stats_with_date(): void
    {
        $this->travelTo(now()->setTimezone('America/Chicago')->startOfDay()->addHours(12));

        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);
        $this->seedRepoMonths($user->org_id);

        $total1 = fake()->numberBetween(1, 100);
        cache()->put($this->cacheKey(
            key: 'today',
            orgId: $user->org_id,
            class: RecoveryCounts::class,
        ), $total1);

        $total2 = fake()->numberBetween(1, 100);
        cache()->put($this->cacheKey(
            key: 'last_7d',
            orgId: $user->org_id,
            class: RecoveryCounts::class,
        ), $total2);

        Livewire::test(RecoveryCounts::class, ['date' => 'today', 'expanded' => true])
            ->assertSuccessful()
            ->dispatch('refresh-dashboard', 'today')
            ->assertSeeText('Today: '.number_format($total1))
            ->dispatch('refresh-dashboard', 'last_7d')
            ->assertSeeText('Last 7 Days: '.number_format($total2));
    }

    /**
     * TDD pin 1 (DEV-2651 t5). Fails against main: the pre-batch card had no
     * runRepoSeriesBatch() seam at all, so RecordingRecoveryCounts::reposCalls never moves off
     * 0 and the assertion below fails immediately.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_the_headline_issues_one_batched_call_not_thirteen(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $widget = new RecordingRecoveryCounts;
        $widget->reposResult = ['2026-08' => 40, '2026-07' => 35];

        $total = $widget->getCardTotal();

        $this->assertSame(1, $widget->reposCalls, 'thirteen months must be ONE batched call, not thirteen');
        $this->assertSame(40, $total, 'the headline value must be unchanged by the batching');
    }

    /**
     * TDD pin 2 / decision 9. The ratio's denominator must be exactly what getMonthlyData()
     * (the headline's own array) returns -- never a second, independently-bucketed fetch. A
     * StubbingHeadlineRecoveryCounts that overrides ONLY getMonthlyData() with an arbitrary
     * fixture proves the zip reads that array: if ratioMonthlySeries() instead called
     * fetchRepoSeries() directly, it would bypass this stub and hit the real (unstubbed, real
     * ClickHouse-only) query, which is not available in this test environment and would fail.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_the_ratio_is_zipped_against_the_headlines_own_month_array(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        $widget = new StubbingHeadlineRecoveryCounts;
        $widget->headline = array_fill(0, 13, 0);
        $widget->headline[12] = 40;
        $widget->headline[11] = 35;
        $widget->scans = ['2026-08' => 200, '2026-07' => 70];

        $series = $this->invoke($widget, 'ratioMonthlySeries', []);

        $this->assertSame(5.0, $series[12], '200 scans / 40 repos, both read from the headline array');
        $this->assertSame(2.0, $series[11], '70 scans / 35 repos, both read from the headline array');
    }

    /**
     * The PARTIAL gate is gone. This card used to render its Total Repossessions headline for
     * everyone while Pro-gating only the "scans per repo" ratio (DEV-2651 decision 3); the
     * `canViewRatioFigure()` override that did it was removed with the rest of the grid's
     * gates, so the ratio now follows the card and needs no entitlement.
     *
     * The zero-cost half of the old test is KEPT below, re-pointed at a role-less viewer --
     * the only way the figure is withheld now -- because "a withheld figure must never invoke
     * the numerator seam" is the guarantee worth protecting, not the entitlement that used to
     * trigger it.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_the_ratio_needs_no_entitlement(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $this->assertTrue(RecoveryCounts::canViewRatioFigure());

        $widget = new RecordingRecoveryCounts;
        $widget->reposResult = ['2026-08' => 40];
        $widget->scansResult = ['2026-08' => 200];

        $stat = $widget->toStat();

        $this->assertSame('40', $stat->getValue());
        $this->assertSame('5.0 scans per repo', (string) $stat->getDescription(), 'a plain ADMIN now sees the ratio');
    }

    /**
     * The surviving withholding path: no ADMIN/USER role. The numerator seam must not be
     * invoked, and the right-hand axis and legend must drop with the dataset.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_a_viewer_without_the_role_never_computes_the_ratio(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(permissions: []));

        $this->assertFalse(RecoveryCounts::canViewRatioFigure());

        $widget = new RecordingRecoveryCounts;
        $widget->reposResult = ['2026-08' => 40];

        $stat = $widget->toStat();

        $this->assertSame('40', $stat->getValue(), 'the headline must still render its real value');
        $this->assertNull($stat->getDescription());
        $this->assertSame(0, $widget->scansCalls, 'the numerator seam must never be invoked for a withheld figure');

        $data = $widget->getData();
        $this->assertCount(1, $data['datasets']);
        $this->assertSame(0, $widget->scansCalls, 'getData() must not compute the ratio either');

        $options = $this->invoke($widget, 'getOptions', []);
        $this->assertArrayNotHasKey('y1', $options['scales']);
        $this->assertFalse($options['plugins']['legend']['display']);
    }

    /**
     * TDD pin 4. For an entitled ADMIN: description present, two datasets, y1 present.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_an_entitled_admin_sees_the_ratio_description_and_two_datasets(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $this->actingAs($user);

        $this->assertTrue(RecoveryCounts::canViewRatioFigure());

        $widget = new RecordingRecoveryCounts;
        $widget->reposResult = ['2026-08' => 40];
        $widget->scansResult = ['2026-08' => 200];

        $stat = $widget->toStat();

        $this->assertSame('40', $stat->getValue(), 'the headline value is unaffected by the ratio gate');
        $this->assertNotNull($stat->getDescription());
        $this->assertStringContainsString('scans per repo', (string) $stat->getDescription());
        $this->assertSame('5.0 scans per repo', (string) $stat->getDescription());

        $data = $widget->getData();
        $this->assertCount(2, $data['datasets']);

        // Human-QA finding: the count series arrived from parent::getData() wearing
        // makeDataset()'s PER-MONTH colour array, and Chart.js reads index 0 of it for the
        // legend swatch -- the leftmost month, never the highlighted one. Restated once the
        // ratio turns a legend on.
        foreach (['backgroundColor', 'hoverBackgroundColor', 'borderColor', 'hoverBorderColor'] as $key) {
            $this->assertIsString($data['datasets'][0][$key], "count series '{$key}' must be ONE colour");
        }

        $this->assertSame('line', $data['datasets'][1]['type'], 'the ratio must not be a second bar');
        $this->assertNotSame($data['datasets'][0]['backgroundColor'], $data['datasets'][1]['backgroundColor']);
        $this->assertLessThan(
            $data['datasets'][0]['order'],
            $data['datasets'][1]['order'],
            'Chart.js draws datasets in REVERSE index order, so the bars bury the line unless the line carries a lower order'
        );

        $options = $this->invoke($widget, 'getOptions', []);
        $this->assertArrayHasKey('y1', $options['scales']);
        $this->assertSame('right', $options['scales']['y1']['position']);
        $this->assertFalse($options['scales']['y1']['grid']['drawOnChartArea']);
        $this->assertTrue($options['scales']['y']['title']['display']);
        $this->assertTrue($options['scales']['y1']['title']['display']);
    }

    /** An org with no repossessions in a month is normal, not an error. */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_a_zero_denominator_renders_zero_with_no_division_error(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        $widget = new RecordingRecoveryCounts;
        $widget->reposResult = ['2026-08' => 0];
        $widget->scansResult = ['2026-08' => 500];

        $this->assertSame('0.0 scans per repo', (string) $widget->toStat()->getDescription());
    }

    /**
     * A numerator failure must degrade only the ratio, never the headline: the two read
     * different tables, and a swallowed scans_cold failure must not become a fabricated 0.0
     * silently indistinguishable from a real reading.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_a_failed_numerator_degrades_the_ratio_but_not_the_headline(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        $widget = new ThrowingNumeratorRecoveryCounts;
        $widget->reposResult = ['2026-08' => 200, '2026-07' => 180];

        $stat = $widget->toStat();

        $this->assertSame('200', $stat->getValue(), 'the headline must not be affected by a numerator outage');
        // Decision 8: the same UNAVAILABLE_VALUE shape UniqueLprCaseClaims and LprRepossessions
        // render, so three adjacent cards degrade identically.
        $this->assertSame(
            RecoveryCounts::UNAVAILABLE_VALUE.' scans per repo',
            (string) $stat->getDescription()
        );

        $data = $widget->getData();
        $this->assertCount(1, $data['datasets'], 'no ratio series may be drawn from a numerator that failed');

        $options = $this->invoke($widget, 'getOptions', []);
        $this->assertArrayNotHasKey('y1', $options['scales']);
    }

    /** The window arm (today/last_7d) must resolve the same way as the monthly arm. */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_the_window_arm_renders_the_ratio_when_entitled(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        $widget = new WindowStubbingRecoveryCounts;
        $widget->date = 'today';
        $widget->windowRepos = 40;
        $widget->windowScans = ['totals' => [0 => 200], 'names' => []];

        $this->assertSame('5.0 scans per repo', (string) $widget->toStat()->getDescription());
    }

    /**
     * refreshData() must clear this card's own $windowRatioMemo, not just the base class's
     * $monthlyDataMemo -- isolated from Laravel's cache layer entirely (both round trips are
     * overridden directly) so only the in-request memo is under test.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_refresh_data_clears_this_cards_own_memos(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        $widget = new CountingWindowRecoveryCounts;
        $widget->date = 'today';

        $this->invoke($widget, 'windowRatio', []);
        $this->invoke($widget, 'windowRatio', []);
        $this->assertSame(1, $widget->windowCalls, 'the window memo must not requery within one request');

        $widget->refreshData('last_7d');
        $this->invoke($widget, 'windowRatio', []);

        $this->assertSame(2, $widget->windowCalls, 'the window memo must not survive a refresh');
    }

    /** The label axis must co-vary with the UTC data axis. */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_graph_labels_are_utc_anchored_and_thirteen_long(): void
    {
        $this->freezeUtc('2026-01-01 02:00:00');

        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $labels = (new RecoveryCounts)->getGraphLabels();

        $this->assertNotSame('UTC', $user->timezone, 'guard: the viewer must not be UTC, or this test proves nothing');
        $this->assertCount(13, $labels);
        $this->assertSame("Jan '26", $labels[12]);
        $this->assertSame([$labels[12]], $this->invoke(new RecoveryCounts, 'getHighlightedMonthsFromFilter', []));
    }

    /** The card's headline now reads from RecoveryTotalsBatchQuery's SQL, not an Eloquent count. */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_card_query_statements_use_the_batched_query(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $widget = new RecoveryCounts;
        $widget->date = 'this_month';

        $statements = $widget->getCardQueryStatements();

        $this->assertCount(1, $statements);
        $this->assertSame(RecoveryTotalsBatchQuery::class, $statements[0]->queryClass);
        $this->assertStringContainsString((string) $user->org_id, $statements[0]->sql);
    }

    /**
     * DEV-2651 t8. Folded forward from the deleted UniqueScansToRepoTest -- this card now OWNS
     * RecoveryTotalsBatchQuery's shared-key namespace (its headline IS the ratio's denominator),
     * so the key-string pin belongs here rather than on a ClickHouse-gated query test.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_the_shared_cache_key_strings_are_pinned(): void
    {
        $this->assertSame(
            'recovery-totals:month-v1:org=42:2026-08',
            RecoveryTotalsBatchQuery::sharedMonthCacheKey(42, '2026-08')
        );
        $this->assertSame(
            'recovery-totals:window-v1:org=42:today:2026-08-12:tz=America/Chicago',
            RecoveryTotalsBatchQuery::sharedWindowCacheKey(42, 'today', '2026-08-12', 'America/Chicago')
        );
    }

    /**
     * DEV-2651 t8. Folded forward from the deleted UniqueScansToRepoTest -- this card's own
     * getMonthlyData() (formerly UniqueScansToRepo's "denominator") must probe each month,
     * write only the uncached ones under the shared key, and never re-fetch a cached month.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_past_months_are_cached_per_month_and_not_refetched(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $widget = new RecordingRecoveryCounts;
        $widget->reposResult = ['2026-08' => 7, '2026-07' => 9];

        $first = $this->invoke($widget, 'fetchRepoSeries', [$user->org_id, now('UTC')]);
        $this->assertSame(1, $widget->reposCalls, 'thirteen months must be ONE batched call, not thirteen');
        $this->assertSame(7, $first['2026-08']);
        $this->assertSame(9, $first['2026-07']);

        $cachedMonth = cache()->get(RecoveryTotalsBatchQuery::sharedMonthCacheKey($user->org_id, '2026-07'));

        $this->assertIsInt($cachedMonth);
        $this->assertSame(
            9,
            $cachedMonth,
            'past months must be written under the shared key this card now owns'
        );

        $this->invoke($widget, 'fetchRepoSeries', [$user->org_id, now('UTC')]);
        $this->assertSame(1, $widget->reposCalls, 'a fully cached series must issue no query');

        cache()->forget(RecoveryTotalsBatchQuery::sharedMonthCacheKey($user->org_id, '2026-08'));

        $this->invoke($widget, 'fetchRepoSeries', [$user->org_id, now('UTC')]);

        $this->assertSame(2, $widget->reposCalls);
        $this->assertSame(
            '2026-08-01',
            $widget->reposStart,
            'the refetch must span the CURRENT month only -- closed months must never be re-scanned'
        );
    }

    /**
     * DEV-2651 t8. Folded forward from the deleted UniqueScansToRepoTest. The batch window is
     * handed DATE strings and covers the WHOLE current month rather than stopping at today --
     * `repo_date` is a business date that can legitimately sit in the future, and this card's
     * own current-month window already used endOfMonth() before this ticket.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_the_batch_window_is_dates_and_covers_the_whole_current_month(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $widget = new RecordingRecoveryCounts;
        $this->invoke($widget, 'fetchRepoSeries', [$user->org_id, now('UTC')]);

        $this->assertSame('2025-08-01', $widget->reposStart, 'the span starts 12 months back');
        $this->assertSame('2026-08-31', $widget->reposEnd, 'the whole current month must be covered, not just today');
    }

    /**
     * DEV-2651 t8. Folded forward from the deleted UniqueScansToRepoTest -- decision d1's
     * propagate-don't-swallow stance. A swallowed failure here is indistinguishable from a
     * genuine zero-repo month, and this card read the same table through Eloquent with no catch
     * anywhere before this ticket; catching now would only make it quietly wrong.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_a_repo_series_clickhouse_failure_propagates(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $widget = new ThrowingRepoSeriesRecoveryCounts;

        $this->expectException(\RuntimeException::class);

        $this->invoke($widget, 'fetchRepoSeries', [$user->org_id, now('UTC')]);
    }

    /**
     * The other half of that decision: because the failure never reaches the write loop, it can
     * never persist a fabricated 0 under a key whose closed months carry a one-year TTL.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_a_failed_repo_series_writes_no_fabricated_zero(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $widget = new ThrowingRepoSeriesRecoveryCounts;

        try {
            $this->invoke($widget, 'fetchRepoSeries', [$user->org_id, now('UTC')]);
        } catch (\RuntimeException) {
            // expected -- see the test above
        }

        $this->assertNull(
            cache()->get(RecoveryTotalsBatchQuery::sharedMonthCacheKey($user->org_id, '2026-07')),
            'a failed batch must leave the closed-month key EMPTY, never a 0 cached for a year'
        );
    }

    /**
     * DEV-2651 t8. Folded forward from the deleted UniqueScansToRepoTest -- the window
     * headline is cached under the class-neutral shared key, so a SECOND card instance in the
     * same request (an expanded-card re-render) reuses it rather than issuing a second query.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_the_window_headline_is_cached_and_not_requeried(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $first = new WindowStubbingRecoveryCounts;
        $first->date = 'today';
        $first->windowRepos = 40;
        $first->windowScans = ['totals' => [0 => 250], 'names' => []];
        $first->getCardTotal();

        $end = $this->invoke($first, 'resolveCardRange', [])[1];
        $windowKey = RecoveryTotalsBatchQuery::sharedWindowCacheKey(
            $user->org_id,
            'today',
            Carbon::parse($end->toDateTimeString())->toDateString(),
            $user->timezone ?? 'UTC',
        );

        $this->assertStringNotContainsString(class_basename(RecoveryCounts::class), $windowKey);
        $this->assertStringNotContainsString(class_basename($first), $windowKey);

        $cachedWindow = cache()->get($windowKey);

        $this->assertIsInt($cachedWindow);
        $this->assertSame(40, $cachedWindow);

        $second = new WindowStubbingRecoveryCounts;
        $second->date = 'today';
        $second->windowRepos = 999;
        $second->windowScans = ['totals' => [0 => 250], 'names' => []];

        $this->assertSame(40, $second->getCardTotal(), 'the cached 40 must win, not the fresh 999');
        $this->assertSame(1, $first->repoWindowCalls);
        $this->assertSame(0, $second->repoWindowCalls, 'the second instance must not re-query');
    }

    /** DEV-2651 t8. Folded forward from the deleted UniqueScansToRepoTest / UniqueScansToCaseClaimsTest. */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_a_null_org_returns_zeros_rather_than_querying(): void
    {
        $this->freezeUtc('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new RecordingRecoveryCounts;

        $this->assertSame([], $this->invoke($widget, 'fetchRepoSeries', [null, now('UTC')]));
        $this->assertSame(0, $widget->reposCalls, 'a null org must never reach the batch seam');
    }

    /**
     * DEV-2652 t4. Scans follow Chicago and end in July. The repo series is still the UTC
     * August month, and its shared key carries no :tz=.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_ratio_numerator_uses_chicago_while_repo_date_stays_on_the_utc_month(): void
    {
        $this->freezeUtc('2026-08-01 02:00:00');

        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $this->assertSame('America/Chicago', $user->timezone);
        $this->assertSame('2026-08', now('UTC')->format('Y-m'), 'precondition: UTC is already August');
        $this->assertSame('2026-07', now(tz: $user->timezone)->format('Y-m'), 'precondition: the viewer is still in July');

        $widget = new RecordingRecoveryCounts;
        $widget->reposResult = ['2026-08' => 7];

        $this->invoke($widget, 'ratioMonthlySeries', []);

        $this->assertSame('America/Chicago', $widget->scansTimezone);
        $this->assertSame('2026-07', Carbon::parse((string) $widget->scansEnd)->format('Y-m'));
        $this->assertStringStartsWith('2026-08', (string) $widget->reposEnd);

        $repoKey = RecoveryTotalsBatchQuery::sharedMonthCacheKey($user->org_id, '2026-08');
        $this->assertStringNotContainsString(':tz=', $repoKey);
        $this->assertSame(7, cache()->get($repoKey));
        $this->assertSame(
            'recovery-totals:month-v1:org=1:2026-08',
            RecoveryTotalsBatchQuery::sharedMonthCacheKey(1, '2026-08'),
        );
    }

    /**
     * CST/PST/+05:00 become UTC. US/Central is kept when this PHP can construct it
     * and otherwise becomes America/Chicago. toStat() must not throw for either.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsafeViewerZones(): array
    {
        return [
            'CST' => ['CST', 'UTC'],
            'PST' => ['PST', 'UTC'],
            '+05:00' => ['+05:00', 'UTC'],
            'US/Central' => ['US/Central', self::expectedLegacyTimezone('US/Central')],
        ];
    }

    /**
     * Alias when this PHP can construct it; otherwise the canonical IANA id.
     */
    private static function expectedLegacyTimezone(string $alias): string
    {
        $canonical = match ($alias) {
            'US/Central' => 'America/Chicago',
            'US/Eastern' => 'America/New_York',
            'US/Pacific' => 'America/Los_Angeles',
            'Asia/Calcutta' => 'Asia/Kolkata',
            default => throw new \InvalidArgumentException("Unknown legacy alias [{$alias}]"),
        };

        try {
            new \DateTimeZone($alias);

            return $alias;
        } catch (\DateInvalidTimeZoneException|\Exception) {
            return $canonical;
        }
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    #[DataProvider('unsafeViewerZones')]
    public function test_ratio_numerator_records_the_sanitised_zone_and_to_stat_does_not_throw(string $zone, string $expected): void
    {
        $this->freezeUtc('2026-08-01 02:00:00');

        $user = $this->createUser(
            attributes: ['dti' => ['timeZone' => $zone]],
            role: RoleEnum::ADMIN->value,
        );
        $this->actingAs($user);

        $widget = new RecordingRecoveryCounts;
        $widget->toStat();

        $this->assertSame($expected, $widget->scansTimezone);
    }

    /**
     * Freeze the clock at an explicit UTC instant -- Carbon::setTestNow() alone parses in
     * config('app.timezone'), which is not pinned by phpunit.xml.
     */
    private function freezeUtc(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    }

    private function seedRepoMonths(int $orgId, int $value = 0): void
    {
        $through = now('UTC');

        for ($i = 12; $i >= 0; $i--) {
            $monthKey = $through->clone()->startOfMonth()->subMonths($i)->format('Y-m');
            cache()->put(RecoveryTotalsBatchQuery::sharedMonthCacheKey($orgId, $monthKey), $value);
        }
    }

    private function invoke(object $widget, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($widget, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($widget, $args);
    }
}

/** Stubs only the BATCH seams, so the real cache probe/span/write loop of both legs run. */
class RecordingRecoveryCounts extends RecoveryCounts
{
    public int $scansCalls = 0;

    public int $reposCalls = 0;

    /** @var array<string, int> */
    public array $scansResult = [];

    /** @var array<string, int> */
    public array $reposResult = [];

    /** DEV-2651 t8: records the span actually handed to the batch, folded forward from UniqueScansToRepoTest. */
    public ?string $reposStart = null;

    public ?string $reposEnd = null;

    public ?string $scansStart = null;

    public ?string $scansEnd = null;

    public ?string $scansTimezone = null;

    protected function runUniqueScansBatch(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->scansCalls++;
        $this->scansStart = $start;
        $this->scansEnd = $end;
        $this->scansTimezone = $timezone;

        return $this->scansResult;
    }

    protected function runRepoSeriesBatch(int $orgId, string $start, string $end): array
    {
        $this->reposCalls++;
        $this->reposStart = $start;
        $this->reposEnd = $end;

        return $this->reposResult;
    }
}

/**
 * DEV-2651 t8. Proves a repo-series ClickHouse failure propagates rather than rendering as a
 * fabricated 0.0 -- folded forward from the deleted UniqueScansToRepoTest.
 */
class ThrowingRepoSeriesRecoveryCounts extends RecoveryCounts
{
    protected function runRepoSeriesBatch(int $orgId, string $start, string $end): array
    {
        throw new \RuntimeException('recoveries table down');
    }
}

/** Replaces the headline array outright, so the ratio zip is exercised against a known fixture. */
class StubbingHeadlineRecoveryCounts extends RecoveryCounts
{
    /** @var array<int, int> */
    public array $headline = [];

    /** @var array<string, int> */
    public array $scans = [];

    protected function getMonthlyData(bool $formatted = true): array
    {
        return $this->headline;
    }

    protected function fetchUniqueScansSeries(?int $orgId, CarbonInterface $through, string $seriesZone = 'UTC'): array
    {
        return $this->scans;
    }
}

class ThrowingNumeratorRecoveryCounts extends RecoveryCounts
{
    /** @var array<string, int> */
    public array $reposResult = [];

    protected function runRepoSeriesBatch(int $orgId, string $start, string $end): array
    {
        return $this->reposResult;
    }

    protected function runUniqueScansBatch(int $orgId, string $start, string $end, string $timezone): array
    {
        throw new \RuntimeException('scans_cold down');
    }
}

/** Counts windowRatio()'s two round trips directly, bypassing Laravel's cache layer entirely. */
class CountingWindowRecoveryCounts extends RecoveryCounts
{
    public int $windowCalls = 0;

    protected function currentPeriodTotal(): int
    {
        $this->windowCalls++;

        return 40;
    }

    protected function windowUniqueScans(?int $orgId, CarbonInterface $start, CarbonInterface $end, string $timezone): int
    {
        return 200;
    }
}

/**
 * Drives the real window cache path with both round trips stubbed. The SERIES leg
 * (getMonthlyData()) is stubbed too: toStat() also builds the sparkline through
 * chartSeries() -> getMonthlyData(), so leaving it live would put a real (ClickHouse-only)
 * query behind a test about the window arm.
 */
class WindowStubbingRecoveryCounts extends RecoveryCounts
{
    public int $windowRepos = 0;

    public int $repoWindowCalls = 0;

    /** @var array{totals: array<int, int>, names: array<int, string>} */
    public array $windowScans = ['totals' => [0 => 0], 'names' => []];

    protected function getMonthlyData(bool $formatted = true): array
    {
        return array_fill(0, 13, 0);
    }

    protected function runRepoWindowQuery(int $orgId, string $start, string $end): int
    {
        $this->repoWindowCalls++;

        return $this->windowRepos;
    }

    protected function fetchUniqueScansRange(int $orgId, string $start, string $end, string $timezone): array
    {
        return $this->windowScans;
    }
}
