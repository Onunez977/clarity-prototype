<?php

namespace Tests\Filament\Widgets\Dashboard;

use App\Clickhouse\Queries\RecoveryLeaderQuery;
use App\Filament\Widgets\Dashboard\Concerns\StatHelper;
use App\Filament\Widgets\Dashboard\RecoveryLeader;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class RecoveryLeaderTest extends TestCase
{
    use StatHelper;

    private mixed $realDatabaseManager = null;

    protected function tearDown(): void
    {
        if ($this->realDatabaseManager !== null) {
            DB::swap($this->realDatabaseManager);
            $this->realDatabaseManager = null;
        }

        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_can_render(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $this->stubRecoveryLeaderQuery([]);

        Livewire::test(RecoveryLeader::class)
            ->assertSuccessful()
            ->assertSeeText('Recovery Leaderboard');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_expanded_recovery_leaderboard_is_not_lazy(): void
    {
        $this->assertFalse(RecoveryLeader::isLazy());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_cannot_render_without_permission(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        Livewire::test(RecoveryLeader::class)
            ->assertForbidden()
            ->assertDontSeeText('Recovery Leaderboard');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_can_render_stats(): void
    {
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $leader = fake()->name();
        $count = fake()->numberBetween(50, 100);

        $timezone = $user->timezone ?? 'UTC';
        $start = now(tz: $timezone)->startOfDay()->toDateString();
        $end = now(tz: $timezone)->endOfDay()->toDateString();

        $this->stubRecoveryLeaderQuery([
            $start.'|'.$end => [$leader => $count],
        ]);

        Livewire::test(RecoveryLeader::class, ['date' => 'today', 'expanded' => true])
            ->assertSuccessful()
            ->dispatch('refresh-dashboard', 'today')
            ->assertSeeText('Leader for Today:')
            ->assertSeeText($leader)
            ->assertSeeText('with '.number_format($count));
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_can_render_runner_up(): void
    {
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $runnerUp = fake()->words(3, true);
        $runnerUpCount = fake()->numberBetween(50, 79);

        $leaderData = [
            fake()->words(3, true) => fake()->numberBetween(80, 100),
            $runnerUp => $runnerUpCount,
        ];

        $timezone = $user->timezone ?? 'UTC';
        $start = now(tz: $timezone)->startOfDay()->toDateString();
        $end = now(tz: $timezone)->endOfDay()->toDateString();

        $this->stubRecoveryLeaderQuery([
            $start.'|'.$end => $leaderData,
        ]);

        Livewire::test(RecoveryLeader::class, ['date' => 'today', 'expanded' => true])
            ->assertSuccessful()
            ->dispatch('refresh-dashboard', 'today')
            ->assertSeeText('Runner-up for Today:')
            ->assertSeeText($runnerUp)
            ->assertSeeText('with '.number_format($runnerUpCount));
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_can_switch_stats_with_date(): void
    {
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $timezone = $user->timezone ?? 'UTC';

        $leader1 = fake()->words(3, true);
        $todayStart = now(tz: $timezone)->startOfDay()->toDateString();
        $todayEnd = now(tz: $timezone)->endOfDay()->toDateString();

        $leader2 = fake()->words(3, true);
        $last7dStart = now(tz: $timezone)->subDays(7)->startOfDay()->toDateString();
        $last7dEnd = now(tz: $timezone)->endOfDay()->toDateString();

        $this->stubRecoveryLeaderQuery([
            $todayStart.'|'.$todayEnd => [$leader1 => fake()->numberBetween(50, 100)],
            $last7dStart.'|'.$last7dEnd => [$leader2 => fake()->numberBetween(50, 100)],
        ]);

        Livewire::test(RecoveryLeader::class, ['date' => 'today', 'expanded' => true])
            ->assertSuccessful()
            ->dispatch('refresh-dashboard', 'today')
            ->assertSeeText('Leader for Today:')
            ->assertSeeText($leader1)
            ->dispatch('refresh-dashboard', 'last_7d')
            ->assertSeeText('Leader for Last 7 Days:')
            ->assertSeeText($leader2);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_collapsed_card_headlines_the_leader_count_and_names_the_leader(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00'));

        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $timezone = $user->timezone ?? 'UTC';

        $thisMonth = now(tz: $timezone)->startOfMonth();
        $lastMonth = $thisMonth->clone()->subMonth();

        $this->stubRecoveryLeaderQuery([
            $thisMonth->clone()->startOfDay()->toDateString().'|'.$thisMonth->clone()->endOfMonth()->endOfDay()->toDateString() => ['Kit Alpha' => 90, 'Kit Bravo' => 60],
            $lastMonth->clone()->startOfDay()->toDateString().'|'.$lastMonth->clone()->endOfMonth()->endOfDay()->toDateString() => ['Kit Charlie' => 80, 'Kit Delta' => 40],
        ]);

        $summary = (new RecoveryLeader)->getCardSummary();

        $this->assertSame('Kit Alpha', $summary['this_month']['leader']['name']);
        $this->assertSame(90.0, $summary['this_month']['leader']['count']);
        $this->assertSame('Kit Bravo', $summary['this_month']['runner_up']['name']);
        $this->assertSame('Kit Charlie', $summary['last_month']['leader']['name']);

        $thisMonthStat = (new RecoveryLeader);
        $thisMonthStat->date = 'this_month';
        $stat = $thisMonthStat->toStat();
        // DEV-2460: relabelled. This class hardcodes the label in its own toStat() AND
        // carries it on $heading, so an exact assertion here is what catches a half-done
        // relabel that leaves the collapsed card and the expanded chart title disagreeing.
        $this->assertSame('LPR Recovery Leaderboard', $stat->getLabel());
        $this->assertSame('90', $stat->getValue());
        $this->assertLeaderboardCardNamesLeaderOnly($stat, 'Kit Alpha', 'Kit Bravo');
        $this->assertSame('info', $stat->getColor());
        $this->assertGreaterThan(1, \count($stat->getChart()));

        Livewire::test(RecoveryLeader::class)
            ->assertSuccessful()
            ->assertSeeText('LPR Recovery Leaderboard')
            ->assertSeeText('Leader for This Month:')
            ->assertSeeText('Kit Alpha');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_collapsed_card_leader_and_runner_up_follow_the_date_filter(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00'));

        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $timezone = $user->timezone ?? 'UTC';

        $thisMonth = now(tz: $timezone)->startOfMonth();
        $lastMonth = $thisMonth->clone()->subMonth();

        $this->stubRecoveryLeaderQuery([
            $thisMonth->clone()->startOfDay()->toDateString().'|'.$thisMonth->clone()->endOfMonth()->endOfDay()->toDateString() => ['Kit Alpha' => 90, 'Kit Bravo' => 60],
            $lastMonth->clone()->startOfDay()->toDateString().'|'.$lastMonth->clone()->endOfMonth()->endOfDay()->toDateString() => ['Kit Charlie' => 80, 'Kit Delta' => 40],
            now(tz: $timezone)->startOfDay()->toDateString().'|'.now(tz: $timezone)->endOfDay()->toDateString() => ['Today Leader' => 12, 'Today Runner' => 7],
            now(tz: $timezone)->subDays(7)->startOfDay()->toDateString().'|'.now(tz: $timezone)->endOfDay()->toDateString() => ['Week Leader' => 33, 'Week Runner' => 21],
        ]);

        $thisMonthWidget = new RecoveryLeader;
        $thisMonthWidget->date = 'this_month';
        $this->assertSame('90', $thisMonthWidget->toStat()->getValue());
        $this->assertLeaderboardCardNamesLeaderOnly($thisMonthWidget->toStat(), 'Kit Alpha', 'Kit Bravo');

        $lastMonthWidget = new RecoveryLeader;
        $lastMonthWidget->date = 'last_month';
        $this->assertSame('80', $lastMonthWidget->toStat()->getValue());
        $this->assertLeaderboardCardNamesLeaderOnly($lastMonthWidget->toStat(), 'Kit Charlie', 'Kit Delta');

        $todayWidget = new RecoveryLeader;
        $todayWidget->date = 'today';
        $this->assertSame('12', $todayWidget->toStat()->getValue());
        $this->assertLeaderboardCardNamesLeaderOnly($todayWidget->toStat(), 'Today Leader', 'Today Runner');

        $weekWidget = new RecoveryLeader;
        $weekWidget->date = 'last_7d';
        $this->assertSame('33', $weekWidget->toStat()->getValue());
        $this->assertLeaderboardCardNamesLeaderOnly($weekWidget->toStat(), 'Week Leader', 'Week Runner');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_collapsed_card_names_the_leader_even_when_long(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00'));

        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $timezone = $user->timezone ?? 'UTC';

        $thisMonth = now(tz: $timezone)->startOfMonth();
        $longLeader = 'South Austin Recovery Unit Alpha-12';
        $longRunner = 'North Dallas Overnight Kit Bravo';

        $this->stubRecoveryLeaderQuery([
            $thisMonth->clone()->startOfDay()->toDateString().'|'.$thisMonth->clone()->endOfMonth()->endOfDay()->toDateString() => [$longLeader => 12, $longRunner => 9],
        ]);

        $widget = new RecoveryLeader;
        $widget->date = 'this_month';

        // The name is rendered in full and truncated by CSS, never omitted -- the hover title
        // carries it.
        $this->assertLeaderboardCardNamesLeaderOnly($widget->toStat(), $longLeader, $longRunner);
        $this->assertSame('12', $widget->toStat()->getValue());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_grid_chart_follows_the_page_date_filter(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00'));

        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $timezone = $user->timezone ?? 'UTC';

        $thisMonth = now(tz: $timezone)->startOfMonth();

        // A DIFFERENT leader lives under the 'today' range that getDescription() (the
        // expanded/filter-driven view) would read for date=today. If the collapsed
        // summary accidentally inherited getDescription()'s filter switch, it would
        // show 'Sneaky Leader' instead of (or alongside) the fixed this-month leader.
        $this->stubRecoveryLeaderQuery([
            $thisMonth->clone()->startOfDay()->toDateString().'|'.$thisMonth->clone()->endOfMonth()->endOfDay()->toDateString() => ['Fixed Leader' => 55],
            now(tz: $timezone)->startOfDay()->toDateString().'|'.now(tz: $timezone)->endOfDay()->toDateString() => ['Sneaky Leader' => 999],
        ]);

        Livewire::test(RecoveryLeader::class, ['filters' => ['date_toggles' => 'today']])
            ->assertSuccessful()
            ->assertSeeText('Leader for Today:')
            ->assertSeeText('Sneaky Leader')
            ->assertDontSeeText('Fixed Leader');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_get_data_is_one_distinct_recoveries_series_in_query_order(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00'));

        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $timezone = $user->timezone ?? 'UTC';

        $this->stubRecoveryLeaderQuery([
            now(tz: $timezone)->startOfDay()->toDateString().'|'.now(tz: $timezone)->endOfDay()->toDateString() => [
                'Kit Alpha' => 9.5,
                'Kit Bravo' => 4.0,
                'Kit Charlie' => 1.25,
            ],
        ]);

        $widget = new RecoveryLeader;
        $widget->date = 'today';

        $data = $widget->getData();

        $this->assertCount(1, $data['datasets']);
        $this->assertSame('Distinct Recoveries', $data['datasets'][0]['label']);
        $this->assertSame([9.5, 4.0, 1.25], $data['datasets'][0]['data']);
        $this->assertSame(['Kit Alpha', 'Kit Bravo', 'Kit Charlie'], $data['labels']);
        $this->assertArrayNotHasKey('stack', $data['datasets'][0]);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_get_data_follows_the_page_date_filter(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00'));

        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $timezone = $user->timezone ?? 'UTC';
        $thisMonth = now(tz: $timezone)->startOfMonth();

        $this->stubRecoveryLeaderQuery([
            now(tz: $timezone)->startOfDay()->toDateString().'|'.now(tz: $timezone)->endOfDay()->toDateString() => ['Today Kit' => 12.0],
            $thisMonth->clone()->startOfDay()->toDateString().'|'.$thisMonth->clone()->endOfMonth()->endOfDay()->toDateString() => ['Month Kit' => 90.0],
        ]);

        $today = new RecoveryLeader;
        $today->date = 'today';
        $todayData = $today->getData();

        $this->assertSame(['Today Kit'], $todayData['labels']);
        $this->assertSame([12.0], $todayData['datasets'][0]['data']);

        $month = new RecoveryLeader;
        $month->date = 'this_month';
        $monthData = $month->getData();

        $this->assertSame(['Month Kit'], $monthData['labels']);
        $this->assertSame([90.0], $monthData['datasets'][0]['data']);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_the_max_bar_uses_the_leader_fill_and_the_field_stays_zinc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00'));

        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $timezone = $user->timezone ?? 'UTC';
        $today = now(tz: $timezone)->startOfDay()->toDateString().'|'.now(tz: $timezone)->endOfDay()->toDateString();

        $this->stubRecoveryLeaderQuery([
            $today => [
                'Kit Alpha' => 9.5,
                'Kit Bravo' => 4.0,
                'Kit Charlie' => 1.25,
            ],
        ]);

        $widget = new RecoveryLeader;
        $widget->date = 'today';
        $dataset = $widget->getData()['datasets'][0];

        $this->assertSame('rgba(34, 197, 94, 0.6)', $dataset['backgroundColor'][0]);
        $this->assertSame('oklch(0.552 0.016 285.938)', $dataset['backgroundColor'][1]);
        $this->assertSame('oklch(0.552 0.016 285.938)', $dataset['backgroundColor'][2]);
        $this->assertSame('rgba(34, 197, 94, 1)', $dataset['borderColor'][0]);
        $this->assertSame('oklch(0.705 0.015 286.067)', $dataset['borderColor'][1]);
        $this->assertSame('oklch(0.705 0.015 286.067)', $dataset['borderColor'][2]);

        $tied = new RecoveryLeader;
        $tied->date = 'today';
        $this->stubRecoveryLeaderQuery([
            $today => ['Kit A' => 5.0, 'Kit B' => 5.0],
        ]);
        $tiedDataset = $tied->getData()['datasets'][0];

        $this->assertSame(
            ['rgba(34, 197, 94, 0.6)', 'rgba(34, 197, 94, 0.6)'],
            $tiedDataset['backgroundColor'],
        );
        $this->assertSame(
            ['rgba(34, 197, 94, 1)', 'rgba(34, 197, 94, 1)'],
            $tiedDataset['borderColor'],
        );
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_the_word_unique_never_appears_in_recovery_leader_chrome(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00'));

        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $timezone = $user->timezone ?? 'UTC';

        $this->stubRecoveryLeaderQuery([
            now(tz: $timezone)->startOfDay()->toDateString().'|'.now(tz: $timezone)->endOfDay()->toDateString() => ['Kit Alpha' => 9.5],
        ]);

        $widget = new RecoveryLeader;
        $widget->date = 'today';

        $heading = (string) $widget->getHeading();
        $options = (string) (new \ReflectionMethod($widget, 'getOptions'))->invoke($widget);
        $datasetLabel = $widget->getData()['datasets'][0]['label'];

        $this->assertSame('LPR Recovery Leaderboard', $heading);
        $this->assertStringContainsString('Top 10 kits by recoveries', $options);
        $this->assertStringContainsString('Distinct recoveries', $options);
        $this->assertStringContainsString("mode: 'nearest'", $options);
        $this->assertStringNotContainsString('stacked', $options);
        $this->assertStringNotContainsStringIgnoringCase('unique', $heading);
        $this->assertStringNotContainsStringIgnoringCase('unique', $options);
        $this->assertStringNotContainsStringIgnoringCase('unique', $datasetLabel);
        $this->assertSame('Distinct Recoveries', $datasetLabel);
        $this->assertSame('recovery-leader', $widget->getCardKey());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_get_data_handles_an_empty_ranking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00'));

        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $this->stubRecoveryLeaderQuery([]);

        $widget = new RecoveryLeader;
        $widget->date = 'today';
        $data = $widget->getData();

        $this->assertCount(1, $data['datasets']);
        $this->assertSame('Distinct Recoveries', $data['datasets'][0]['label']);
        $this->assertSame([], $data['datasets'][0]['data']);
        $this->assertSame([], $data['labels']);
        $this->assertSame([], $data['datasets'][0]['backgroundColor']);
        $this->assertSame([], $data['datasets'][0]['borderColor']);
    }

    /**
     * DEV-2586: the collapsed card names its leader, and ONLY its leader.
     *
     * The truncate class is asserted, not incidental: theme.css keeps min-h-47 as a floor, so a
     * name allowed to wrap makes this card taller than its row -- the parity 304d3c77e bought by
     * dropping these names. Scope of the claim: this pins that the CARD EMITS the class, nothing
     * more. It does not compile CSS or measure height, so it catches the class being dropped, not
     * the utility being removed from the build.
     */
    private function assertLeaderboardCardNamesLeaderOnly(Stat $stat, string $leader, string $runnerUp): void
    {
        $html = (string) $stat->getDescription();

        $this->assertStringContainsString('Top Kit: '.e($leader), $html);
        $this->assertStringContainsString('truncate', $html);
        $this->assertStringContainsString('title="'.e($leader).'"', $html);
        $this->assertStringNotContainsString($runnerUp, $html);
    }

    /**
     * Build cache key matching ClickhouseQuery::buildCacheKey for RecoveryLeaderQuery.
     */
    private function recoveryLeaderCacheKey(int $orgId, string $start, string $end): string
    {
        return 'recovery-leader-query:org_id='.$orgId.':start='.$start.':end='.$end;
    }

    /**
     * Review-2 M3: RecoveryLeaderQuery returns round(sum(kit_count/total_count), 2) - fractional
     * attribution shares. The collapsed summary used to cast them to int, which TRUNCATED
     * (3.75 -> 3) while the expanded chart's getDescription() hands the same raw value to
     * number_format(), which ROUNDS (3.75 -> 4). The card must not disagree with the chart it
     * expands into, and near-ties must not collapse.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_collapsed_summary_preserves_fractional_attribution_shares(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs($user = $this->createUser(role: RoleEnum::ADMIN->value));

        $timezone = $user->timezone ?? 'UTC';
        $monthStart = now(tz: $timezone)->startOfMonth();

        cache()->put(
            $this->recoveryLeaderCacheKey(
                $user->org_id,
                $monthStart->clone()->startOfDay()->toDateString(),
                $monthStart->clone()->endOfMonth()->endOfDay()->toDateString()
            ),
            ['Fractional Leader' => 3.75, 'Close Second' => 3.10]
        );

        $widget = new RecoveryLeader;
        $widget->date = 'this_month';

        $summary = (new \ReflectionMethod($widget, 'summarizeMonth'))
            ->invoke($widget, ['Fractional Leader' => 3.75, 'Close Second' => 3.10]);

        $this->assertSame(3.75, $summary['leader']['count'], 'the fractional share must survive uncast');
        $this->assertSame(3.10, $summary['runner_up']['count']);

        // And rendered the way the expanded chart renders it: rounded, not truncated.
        $this->assertSame('4', number_format($summary['leader']['count']));
        $this->assertSame('3', number_format($summary['runner_up']['count']));
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_a_non_date_start_is_not_cached_and_returns_empty(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs($user = $this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new RecoveryLeader;
        $result = (new \ReflectionMethod($widget, 'queryClickhouse'))->invoke(
            $widget,
            $user->org_id,
            ['start' => 'not-a-date', 'end' => '2026-08-31'],
        );

        $this->assertSame([], $result);
        $this->assertNull(cache()->get($this->recoveryLeaderCacheKey($user->org_id, 'not-a-date', '2026-08-31')));
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_repo_date_predicates_stay_calendar_dates(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RecoveryLeaderQuery::handleSql([
            'org_id' => 1,
            'start' => '2026-08-01 00:00:00',
            'end' => '2026-08-31',
        ]);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_repo_date_sql_compares_y_m_d_strings(): void
    {
        $sql = RecoveryLeaderQuery::handleSql([
            'org_id' => 1,
            'start' => '2026-08-01',
            'end' => '2026-08-31',
        ]);

        $this->assertStringContainsString("repo_date >= '2026-08-01'", $sql);
        $this->assertStringContainsString("repo_date <= '2026-08-31'", $sql);
        $this->assertStringNotContainsString('toDateTime', $sql);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_impersonation_caches_the_recovery_leader_for_ten_seconds(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs($user = $this->createUser(role: RoleEnum::ADMIN->value));
        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);

        $connection = \Mockery::mock();
        $connection->shouldReceive('select')->once()->andReturn([
            ['name' => 'Kit Alpha', 'total' => 1.5],
        ]);

        $real = DB::getFacadeRoot();
        $manager = \Mockery::mock($real)->makePartial();
        $manager->shouldReceive('connection')->once()->with('clarity_clickhouse')->andReturn($connection);
        DB::swap($manager);

        try {
            $widget = new RecoveryLeader;
            $result = (new \ReflectionMethod($widget, 'queryClickhouse'))->invoke(
                $widget,
                $user->org_id,
                ['start' => '2026-08-12', 'end' => '2026-08-12'],
            );

            $this->assertSame(['Kit Alpha' => 1.5], $result);
            $this->assertSame(
                now()->addSeconds(10)->getTimestamp(),
                $this->cacheExpiresAt($this->recoveryLeaderCacheKey($user->org_id, '2026-08-12', '2026-08-12')),
            );
        } finally {
            DB::swap($real);
        }
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_a_month_that_ended_yesterday_stays_on_the_ten_minute_arm(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs($user = $this->createUser(role: RoleEnum::ADMIN->value));

        $connection = \Mockery::mock();
        $connection->shouldReceive('select')->once()->andReturn([
            ['name' => 'Kit Alpha', 'total' => 2],
        ]);

        $real = DB::getFacadeRoot();
        $manager = \Mockery::mock($real)->makePartial();
        $manager->shouldReceive('connection')->once()->with('clarity_clickhouse')->andReturn($connection);
        DB::swap($manager);

        try {
            $widget = new RecoveryLeader;
            (new \ReflectionMethod($widget, 'queryClickhouse'))->invoke(
                $widget,
                $user->org_id,
                ['start' => '2026-08-01', 'end' => '2026-08-11'],
                1,
                now()->subDay(),
            );

            $this->assertSame(
                now()->addMinutes(10)->getTimestamp(),
                $this->cacheExpiresAt($this->recoveryLeaderCacheKey($user->org_id, '2026-08-01', '2026-08-11')),
            );
        } finally {
            DB::swap($real);
        }
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_a_warm_success_key_is_forgotten_when_the_query_throws(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs($user = $this->createUser(
            attributes: ['dti' => ['timeZone' => 'UTC']],
            role: RoleEnum::ADMIN->value,
        ));

        $key = $this->recoveryLeaderCacheKey($user->org_id, '2026-08-01', '2026-08-31');
        cache()->put($key, ['Happy Alpha' => 9]);

        $this->bindClarityClickhouse(function (): array {
            throw new \RuntimeException('clarity.recovery_scans missing');
        });

        $widget = new RecoveryLeader;
        $result = (new \ReflectionMethod($widget, 'queryClickhouse'))->invoke(
            $widget,
            $user->org_id,
            ['start' => '2026-08-01', 'end' => '2026-08-31'],
        );

        $this->assertSame([], $result);
        $this->assertNull(cache()->get($key));

        $card = new RecoveryLeader;
        $card->date = 'this_month';
        $stat = $card->toStat();

        $this->assertSame('0', $stat->getValue());
        $this->assertStringContainsString('Top Kit: N/A', (string) $stat->getDescription());
        $this->assertStringNotContainsString('Happy Alpha', (string) $stat->getDescription());
        $this->assertNull(cache()->get($key));
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_a_later_success_shows_beta_after_a_thrown_query_forgets_alpha(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs($user = $this->createUser(
            attributes: ['dti' => ['timeZone' => 'UTC']],
            role: RoleEnum::ADMIN->value,
        ));

        $key = $this->recoveryLeaderCacheKey($user->org_id, '2026-08-01', '2026-08-31');
        cache()->put($key, ['Happy Alpha' => 9]);

        $calls = 0;
        $this->bindClarityClickhouse(function () use (&$calls): array {
            $calls++;

            if ($calls === 1) {
                throw new \RuntimeException('clarity.recovery_scans missing');
            }

            return [['name' => 'Happy Beta', 'total' => 4]];
        });

        $widget = new RecoveryLeader;
        $method = new \ReflectionMethod($widget, 'queryClickhouse');
        $first = $method->invoke($widget, $user->org_id, ['start' => '2026-08-01', 'end' => '2026-08-31']);

        $this->assertSame([], $first);
        $this->assertNull(cache()->get($key));

        $second = $method->invoke($widget, $user->org_id, ['start' => '2026-08-01', 'end' => '2026-08-31']);

        $this->assertSame(['Happy Beta' => 4.0], $second);
        $this->assertSame(['Happy Beta' => 4.0], cache()->get($key));

        $card = new RecoveryLeader;
        $card->date = 'this_month';
        $stat = $card->toStat();

        $this->assertSame('4', $stat->getValue());
        $this->assertStringContainsString('Top Kit: Happy Beta', (string) $stat->getDescription());
        $this->assertStringNotContainsString('Happy Alpha', (string) $stat->getDescription());
        $this->assertSame(['Happy Beta' => 4.0], cache()->get($key));
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_invalid_viewer_timezone_renders_utc_counts(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs($user = $this->createUser(
            attributes: ['dti' => ['timeZone' => 'NotAReal/Zone']],
            role: RoleEnum::ADMIN->value,
        ));

        $this->assertSame('NotAReal/Zone', $user->timezone);

        $month = now(tz: 'UTC')->startOfMonth();
        $todayStart = now(tz: 'UTC')->startOfDay()->toDateString();
        $todayEnd = now(tz: 'UTC')->endOfDay()->toDateString();

        $this->stubRecoveryLeaderQuery([
            $month->copy()->startOfDay()->toDateString().'|'.$month->copy()->endOfMonth()->endOfDay()->toDateString() => ['Utc Kit' => 6],
            $todayStart.'|'.$todayEnd => ['Today Utc' => 3],
        ]);

        $widget = new RecoveryLeader;
        $widget->date = 'this_month';
        $stat = $widget->toStat();

        $this->assertSame('6', $stat->getValue());
        $this->assertStringContainsString('Top Kit: Utc Kit', (string) $stat->getDescription());
        $this->assertSame(now(tz: 'UTC')->format('M \'y'), $widget->getGraphLabels()[12]);

        $today = new RecoveryLeader;
        $today->date = 'today';

        $this->assertSame('3', $today->toStat()->getValue());
        $this->assertStringContainsString('Today Utc', (string) $today->getDescription());
        $this->assertStringNotContainsString('Unavailable', (string) $today->getDescription());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_mount_reads_a_nested_filament_date_toggle(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        foreach (['last_month', 'today', 'last_7d'] as $toggle) {
            Livewire::test(RecoveryLeader::class, [
                'filters' => ['date' => ['date_toggles' => $toggle]],
            ])->assertSet('date', $toggle);
        }
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_mount_reads_a_nested_page_filter_date_toggle(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        Livewire::test(RecoveryLeader::class, [
            'pageFilters' => ['date' => ['date_toggles' => 'last_month']],
        ])->assertSet('date', 'last_month');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_mount_keeps_an_injected_date_when_filters_omit_the_toggle(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        Livewire::test(RecoveryLeader::class, [
            'date' => 'last_7d',
            'filters' => [],
        ])->assertSet('date', 'last_7d');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_nested_page_date_drives_the_expanded_ranking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00'));

        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $timezone = $user->timezone ?? 'UTC';
        $thisMonth = now(tz: $timezone)->startOfMonth();
        $lastMonth = $thisMonth->clone()->subMonth();

        $this->stubRecoveryLeaderQuery([
            $thisMonth->clone()->startOfDay()->toDateString().'|'.$thisMonth->clone()->endOfMonth()->endOfDay()->toDateString() => ['This Month Kit' => 90.0],
            $lastMonth->clone()->startOfDay()->toDateString().'|'.$lastMonth->clone()->endOfMonth()->endOfDay()->toDateString() => ['Last Month Kit' => 11.5, 'Last Month Runner' => 4.0],
            now(tz: $timezone)->startOfDay()->toDateString().'|'.now(tz: $timezone)->endOfDay()->toDateString() => ['Today Kit' => 3.0],
            now(tz: $timezone)->subDays(7)->startOfDay()->toDateString().'|'.now(tz: $timezone)->endOfDay()->toDateString() => ['Last 7d Kit' => 8.0],
        ]);

        $lastMonthComponent = Livewire::test(RecoveryLeader::class, [
            'expanded' => true,
            'filters' => ['date' => ['date_toggles' => 'last_month']],
        ]);

        $lastMonthComponent
            ->assertSet('date', 'last_month')
            ->assertSeeText('Leader for Last Month:')
            ->assertSeeText('Last Month Kit')
            ->assertDontSeeText('Leader for This Month:');

        $lastMonthData = $lastMonthComponent->instance()->getData();
        $this->assertSame(['Last Month Kit', 'Last Month Runner'], $lastMonthData['labels']);
        $this->assertSame([11.5, 4.0], $lastMonthData['datasets'][0]['data']);

        $todayComponent = Livewire::test(RecoveryLeader::class, [
            'expanded' => true,
            'filters' => ['date' => ['date_toggles' => 'today']],
        ]);

        $todayComponent
            ->assertSet('date', 'today')
            ->assertSeeText('Leader for Today:')
            ->assertSeeText('Today Kit')
            ->assertDontSeeText('This Month Kit');

        $this->assertSame(['Today Kit'], $todayComponent->instance()->getData()['labels']);

        $last7dComponent = Livewire::test(RecoveryLeader::class, [
            'expanded' => true,
            'pageFilters' => ['date' => ['date_toggles' => 'last_7d']],
        ]);

        $last7dComponent
            ->assertSet('date', 'last_7d')
            ->assertSeeText('Leader for Last 7 Days:')
            ->assertSeeText('Last 7d Kit');

        $this->assertSame(['Last 7d Kit'], $last7dComponent->instance()->getData()['labels']);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('recoveries')]
    public function test_refresh_dashboard_still_updates_the_open_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00'));

        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $timezone = $user->timezone ?? 'UTC';
        $lastMonth = now(tz: $timezone)->startOfMonth()->subMonth();

        $this->stubRecoveryLeaderQuery([
            now(tz: $timezone)->startOfDay()->toDateString().'|'.now(tz: $timezone)->endOfDay()->toDateString() => ['Today Kit' => 3.0],
            $lastMonth->clone()->startOfDay()->toDateString().'|'.$lastMonth->clone()->endOfMonth()->endOfDay()->toDateString() => ['Last Month Kit' => 11.5],
        ]);

        Livewire::test(RecoveryLeader::class, [
            'expanded' => true,
            'filters' => ['date' => ['date_toggles' => 'today']],
        ])
            ->assertSet('date', 'today')
            ->assertSeeText('Leader for Today:')
            ->dispatch('refresh-dashboard', 'last_month')
            ->assertSet('date', 'last_month')
            ->assertSeeText('Leader for Last Month:')
            ->assertSeeText('Last Month Kit')
            ->assertDontSeeText('Leader for Today:');
    }

    private function cacheExpiresAt(string $key): int
    {
        $store = cache()->store()->getStore();
        $this->assertInstanceOf(ArrayStore::class, $store);
        $storage = (new \ReflectionProperty($store, 'storage'))->getValue($store);
        $this->assertArrayHasKey($key, $storage);

        return (int) $storage[$key]['expiresAt'];
    }

    /**
     * @param  array<string, array<string, int|float>>  $rankingsByRange
     */
    private function stubRecoveryLeaderQuery(array $rankingsByRange): void
    {
        $this->bindClarityClickhouse(function (string $sql) use ($rankingsByRange): array {
            if (preg_match("/repo_date >= '(\d{4}-\d{2}-\d{2})'/", $sql, $start) !== 1
                || preg_match("/repo_date <= '(\d{4}-\d{2}-\d{2})'/", $sql, $end) !== 1) {
                return [];
            }

            $ranking = $rankingsByRange[$start[1].'|'.$end[1]] ?? [];
            $rows = [];

            foreach ($ranking as $name => $total) {
                $rows[] = ['name' => $name, 'total' => $total];
            }

            return $rows;
        });
    }

    private function bindClarityClickhouse(\Closure $select): void
    {
        $connection = \Mockery::mock();
        $connection->shouldReceive('select')->andReturnUsing($select);

        if ($this->realDatabaseManager === null) {
            $this->realDatabaseManager = DB::getFacadeRoot();
        }

        $real = $this->realDatabaseManager;
        $manager = \Mockery::mock($real)->makePartial();
        $manager->shouldReceive('connection')->andReturnUsing(function ($name = null) use ($connection, $real) {
            if ($name === 'clarity_clickhouse') {
                return $connection;
            }

            return $real->connection($name);
        });

        DB::swap($manager);
    }
}
