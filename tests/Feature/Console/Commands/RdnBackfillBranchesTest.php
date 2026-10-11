<?php

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\RdnBackfillBranches;
use App\Enums\HotlistStatusEnum;
use App\Jobs\Rdn\GetOrgHotlistCaseInfo;
use App\Models\Branch;
use App\Models\Hotlist;
use App\Models\HotlistMeta;
use App\Models\Org;
use App\Services\RDN;
use Carbon\CarbonImmutable;
use DataDog\DogStatsd;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;
use LaravelClickhouseEloquent\Exceptions\ClickhouseException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

#[Group('clickhouse')]
class RdnBackfillBranchesTest extends TestCase
{
    use RequiresClickhouse;

    private CarbonImmutable $since;

    private CarbonImmutable $until;

    /** @var list<string> Every case id the stubbed RDN client was asked about, in call order. */
    private array $calledCases = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The trait truncates zod.public_hotlist; the meta mirror is this test's own.
        DB::connection('public_clickhouse')->statement('TRUNCATE TABLE IF EXISTS public_hotlist_meta');

        Cache::flush();
        Queue::fake();
        Sleep::fake();
        $this->bindRdnStub();

        // A window wholly in the past: the factory stamps updated_at = now on every insert,
        // and that must not count as activity.
        $this->since = CarbonImmutable::now('UTC')->subDays(200)->startOfDay();
        $this->until = CarbonImmutable::now('UTC')->subDays(100)->startOfDay();
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_selects_only_rdn_hotlists_with_activity_in_window(): void
    {
        $org = $this->rdnOrg();
        $inWindow = $this->since->addDays(10)->toDateTimeString();

        $updatedInWindow = $this->rdnHotlist($org, [
            'status' => HotlistStatusEnum::Closed->value,
            'updated_at' => $inWindow,
        ]);

        // The window is inclusive of --since.
        $atSince = $this->rdnHotlist($org, [
            'status' => HotlistStatusEnum::Closed->value,
            'updated_at' => $this->since->toDateTimeString(),
        ]);

        $repoInWindow = $this->rdnHotlist($org, ['status' => HotlistStatusEnum::Closed->value]);
        $this->repoDate($repoInWindow, $this->since->addDays(20)->toDateString());

        $open = $this->rdnHotlist($org, ['status' => HotlistStatusEnum::Open->value]);
        $hold = $this->rdnHotlist($org, ['status' => HotlistStatusEnum::Hold->value]);

        // Created long before the window, repossessed inside it: activity, not created_at.
        $oldRepo = $this->rdnHotlist($org, [
            'status' => HotlistStatusEnum::Repossessed->value,
            'created_at' => now()->subYears(2),
        ]);
        $this->repoDate($oldRepo, $this->since->addDays(30)->toDateString());

        // Excluded.
        $this->rdnHotlist($org, ['status' => HotlistStatusEnum::Closed->value]);
        // The window is exclusive of --until.
        $this->rdnHotlist($org, [
            'status' => HotlistStatusEnum::Closed->value,
            'updated_at' => $this->until->toDateTimeString(),
        ]);
        $this->rdnHotlist($org, ['integration_id' => IntegrationEnum::iRepo->value]);
        $this->rdnHotlist($org, ['status' => HotlistStatusEnum::Open->value, 'remote_order_id' => null]);
        $this->rdnHotlist($org, ['status' => HotlistStatusEnum::Invalid->value, 'updated_at' => $inWindow]);
        $enriched = $this->rdnHotlist($org, ['status' => HotlistStatusEnum::Open->value]);
        $this->hotlistJson($enriched, ['branch_id' => 42]);
        $sealed = $this->rdnHotlist($org, ['status' => HotlistStatusEnum::Open->value]);
        $this->hotlistJson($sealed, ['branch_id' => null, GetOrgHotlistCaseInfo::ATTEMPTS_KEY => GetOrgHotlistCaseInfo::maxLookupAttempts()]);
        $this->rdnHotlist($this->rdnOrg(), ['status' => HotlistStatusEnum::Open->value]);
        $afterUntil = $this->rdnHotlist($org, [
            'status' => HotlistStatusEnum::Closed->value,
            'updated_at' => $this->until->addDay()->toDateTimeString(),
        ]);
        $this->repoDate($afterUntil, $this->until->toDateString());

        $this->assertSame(0, $this->runCommand($org));

        $this->assertEqualsCanonicalizing(
            $this->remoteIds([$updatedInWindow, $atSince, $repoInWindow, $open, $hold, $oldRepo]),
            $this->calledCases,
        );
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_selection_sql_never_reads_hotlist_status_log(): void
    {
        $org = $this->rdnOrg();
        $open = $this->rdnHotlist($org, ['status' => HotlistStatusEnum::Open->value]);
        $closed = $this->rdnHotlist($org, [
            'status' => HotlistStatusEnum::Closed->value,
            'updated_at' => $this->since->addDays(10)->toDateTimeString(),
        ]);

        // Registered after the fixtures: the hotlist triggers write hotlist_status_log
        // server-side, and those inserts never reach this listener anyway.
        $sql = [];
        DB::listen(function (QueryExecuted $query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $this->assertSame(0, $this->runCommand($org));
        $this->assertEqualsCanonicalizing($this->remoteIds([$open, $closed]), $this->calledCases);

        $this->assertSame(0, $this->runCommand($org, ['--dry-run' => 2]));

        // An empty capture would mean the listener is not live and the checks below prove nothing.
        $this->assertNotEmpty($sql);

        // The activity rule left Postgres: it only re-checks the candidate ids by primary key.
        foreach ($sql as $statement) {
            $this->assertStringNotContainsString('hotlist_status_log', $statement);
            $this->assertStringNotContainsString('"hotlist_meta"', $statement);
            $this->assertStringNotContainsString('"hotlist"."updated_at"', $statement);
        }

        $this->assertNotEmpty(
            array_filter($sql, fn (string $statement): bool => str_contains($statement, '"hotlist"."id" in (')),
            'Postgres re-check has no id list',
        );

        // The ClickHouse selection SQL (compiled, not executed) reads the CDC mirrors with FINAL.
        $command = app(RdnBackfillBranches::class);
        $candidates = new \ReflectionMethod($command, 'candidates');

        foreach ([null, ['created_at' => '2026-01-01 00:00:00.123456', 'id' => 7]] as $cursor) {
            $clickhouse = $candidates->invoke($command, $org, $this->since, $this->until, $cursor)->toSql();

            $this->assertStringContainsString('public_hotlist', $clickhouse);
            $this->assertMatchesRegularExpression('/public_hotlist`?\s+FINAL\s+where/i', $clickhouse);
            $this->assertStringContainsString('public_hotlist_meta FINAL WHERE _peerdb_is_deleted = 0', $clickhouse);
            $this->assertStringContainsString('updated_at', $clickhouse);
            $this->assertMatchesRegularExpression('/_peerdb_is_deleted = 0\s+order by/i', $clickhouse);
            $this->assertStringNotContainsString('hotlist_status_log', $clickhouse);
            $this->assertSame($cursor !== null, str_contains($clickhouse, 'toDateTime64(?, 6)'));
        }
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_clickhouse_selection_uses_the_latest_cdc_version(): void
    {
        $org = $this->rdnOrg();

        // A: the replica saw Open, then Closed (out of the window): not a candidate.
        $stale = $this->rdnHotlist($org, ['status' => HotlistStatusEnum::Closed->value], [
            'status' => HotlistStatusEnum::Open->value,
        ]);
        $this->mirrorHotlist($stale, ['_peerdb_version' => 2]);

        // B: the replica saw Open, then a tombstone: not a candidate.
        $deleted = $this->rdnHotlist($org);
        $this->mirrorHotlist($deleted, ['_peerdb_version' => 2, '_peerdb_is_deleted' => 1]);

        // C: the replica saw Closed (out of the window), then Open: the one candidate.
        $reopened = $this->rdnHotlist($org, [], ['status' => HotlistStatusEnum::Closed->value]);
        $this->mirrorHotlist($reopened, ['_peerdb_version' => 2]);

        // D: a repo date in the window, replaced by one outside it: not a candidate.
        $moved = $this->rdnHotlist($org, ['status' => HotlistStatusEnum::Closed->value]);
        $meta = $this->repoDate($moved, $this->until->addDays(5)->toDateString(), ['_peerdb_version' => 2]);
        $this->chHotlistMeta([
            'id' => (int) $meta->id,
            'hotlist_id' => $moved->id,
            'repo_date' => $this->since->addDays(20)->toDateString(),
        ]);

        $this->assertSame(0, $this->runCommand($org));

        $this->assertSame($this->remoteIds([$reopened]), $this->calledCases);
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_skips_clickhouse_candidates_missing_or_changed_on_postgres(): void
    {
        $org = $this->rdnOrg();
        $now = now()->toDateTimeString();

        // On the replica only: no Postgres row.
        $this->chHotlist([
            'id' => 9_000_000_001,
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
            'remote_order_id' => 'EXACT-GHOST',
            'status' => HotlistStatusEnum::Open->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // The replica still says Open; Postgres says Invalid.
        $this->rdnHotlist($org, [
            'remote_order_id' => 'EXACT-INVALID',
            'status' => HotlistStatusEnum::Invalid->value,
        ], ['status' => HotlistStatusEnum::Open->value]);

        // The replica says this org; Postgres says another.
        $this->rdnHotlist($this->rdnOrg(), ['remote_order_id' => 'EXACT-OTHER-ORG'], ['org_id' => $org->id]);

        // The replica says RDN; Postgres says iRepo.
        $this->rdnHotlist($org, [
            'remote_order_id' => 'EXACT-IREPO',
            'integration_id' => IntegrationEnum::iRepo->value,
        ], ['integration_id' => IntegrationEnum::RDN->value]);

        $this->rdnHotlist($org, ['remote_order_id' => 'EXACT-VALID']);

        $this->assertSame(0, $this->runCommand($org));
        $output = Artisan::output();

        $this->assertSame(['EXACT-VALID'], $this->calledCases);
        $this->assertStringContainsString('Complete', $output);
        $this->assertStringContainsString('walked 1', $output);
        $this->assertFalse(Cache::has($this->cursorKey($org)));
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_a_chunk_of_only_sealed_rows_does_not_end_the_run(): void
    {
        $org = $this->rdnOrg();

        // Newest, on the replica, but resolved on Postgres: the first one-row page is all dropped.
        $sealed = $this->rdnHotlist($org, ['remote_order_id' => 'EXACT-SEALED', 'created_at' => now()->subDays(2)]);
        $this->hotlistJson($sealed, ['branch_id' => 42]);
        $this->rdnHotlist($org, ['remote_order_id' => 'EXACT-OLDER', 'created_at' => now()->subDays(3)]);

        $this->assertSame(0, $this->runCommand($org, ['--chunk' => 1]));

        $this->assertSame(['EXACT-OLDER'], $this->calledCases);
        $this->assertStringContainsString('Complete', Artisan::output());
        $this->assertFalse(Cache::has($this->cursorKey($org)));
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_resumes_from_a_microsecond_cursor_applied_in_clickhouse(): void
    {
        $org = $this->rdnOrg();
        $second = now()->subDays(5)->format('Y-m-d H:i:s');

        $newest = $this->rdnHotlist($org, ['remote_order_id' => 'EXACT-1'], mirror: false);
        $middle = $this->rdnHotlist($org, ['remote_order_id' => 'EXACT-2'], mirror: false);
        $oldest = $this->rdnHotlist($org, ['remote_order_id' => 'EXACT-3', 'created_at' => now()->subDays(6)], mirror: false);

        // Two rows in one second, apart only by microseconds. Eloquent writes whole seconds, so set them directly.
        foreach ([[$newest, "{$second}.200000"], [$middle, "{$second}.100000"]] as [$hotlist, $createdAt]) {
            DB::connection('zod')->table('public.hotlist')->where('id', $hotlist->id)->update(['created_at' => $createdAt]);
        }

        foreach ([$newest, $middle, $oldest] as $hotlist) {
            $this->mirrorHotlist($hotlist);
        }

        foreach ([1, 2, 3] as $run) {
            $this->assertSame(0, $this->runCommand($org, ['--chunk' => 1, '--max' => 1]));
            $this->assertStringContainsString('Stopped at --max', Artisan::output());
            $this->assertTrue(Cache::has($this->cursorKey($org)), "cursor after run {$run}");

            if ($run === 1) {
                $this->assertMatchesRegularExpression('/\d\d:\d\d:\d\d\.\d+$/', Cache::get($this->cursorKey($org))['created_at']);
            }
        }

        $this->assertSame(['EXACT-1', 'EXACT-2', 'EXACT-3'], $this->calledCases);

        $this->assertSame(0, $this->runCommand($org, ['--chunk' => 1, '--max' => 1]));

        $this->assertStringContainsString('Complete', Artisan::output());
        $this->assertFalse(Cache::has($this->cursorKey($org)));
        $this->assertSame(['EXACT-1', 'EXACT-2', 'EXACT-3'], $this->calledCases);
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_fails_loudly_without_postgres_fallback_when_clickhouse_is_unavailable(): void
    {
        $org = $this->rdnOrg();
        $this->rdnHotlist($org, ['remote_order_id' => 'EXACT-1']);

        $jsonBefore = DB::connection('zod')->table('public.hotlist_json')->count();

        // An unroutable address with a one-second budget, as in ClickhouseFailureLoggingTest.
        config([
            'database.connections.public_clickhouse.host' => '192.0.2.1',
            'database.connections.public_clickhouse.port' => '8123',
            'database.connections.public_clickhouse.https' => false,
            'database.connections.public_clickhouse.timeout_connect' => 1,
            'database.connections.public_clickhouse.timeout_query' => 1,
            'database.connections.public_clickhouse.retries' => 0,
        ]);
        DB::purge('public_clickhouse');

        $sql = [];
        DB::listen(function (QueryExecuted $query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        foreach ([[], ['--dry-run' => 3]] as $options) {
            $thrown = null;

            try {
                $this->runCommand($org, $options);
            } catch (ClickhouseException $exception) {
                $thrown = $exception;
            }

            $this->assertInstanceOf(ClickhouseException::class, $thrown, json_encode($options));
        }

        $this->assertSame([], $this->calledCases);
        $this->assertSame($jsonBefore, DB::connection('zod')->table('public.hotlist_json')->count());
        $this->assertFalse(Cache::has($this->cursorKey($org)));
        Queue::assertNothingPushed();
        $this->assertSame(0, RateLimiter::attempts(md5('rdn-case-info'.'rdn-case-info:'.$org->id)));

        // An empty capture would mean the listener is not live and the check below proves nothing.
        $this->assertNotEmpty($sql);

        foreach ($sql as $statement) {
            $this->assertStringNotContainsString('from "hotlist"', $statement);
        }
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_dry_run_sample_drops_sealed_rows_and_reports_the_real_count(): void
    {
        $org = $this->rdnOrg();

        $this->rdnHotlist($org, ['remote_order_id' => 'EXACT-1', 'created_at' => now()->subDays(5)]);
        $sealed = $this->rdnHotlist($org, ['remote_order_id' => 'EXACT-2', 'created_at' => now()->subDays(6)]);
        $this->hotlistJson($sealed, ['branch_id' => 42]);

        $jsonBefore = DB::connection('zod')->table('public.hotlist_json')->count();

        // Both rows are in the <30d sample of 2; the sealed one drops out on Postgres, with no refill.
        $this->assertSame(0, $this->runCommand($org, ['--dry-run' => 2]));

        $this->assertMatchesRegularExpression('/<30d\s*\|\s*1\s*\|/', Artisan::output());
        $this->assertSame(['EXACT-1'], $this->calledCases);
        $this->assertSame($jsonBefore, DB::connection('zod')->table('public.hotlist_json')->count());
        $this->assertFalse(Cache::has($this->cursorKey($org)));
        Queue::assertNothingPushed();
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_processes_newest_first_inline_without_queueing(): void
    {
        $org = $this->rdnOrg();
        $oldest = $this->rdnHotlist($org, ['created_at' => now()->subDays(30)]);
        $newest = $this->rdnHotlist($org, ['created_at' => now()->subDay()]);
        $middle = $this->rdnHotlist($org, ['created_at' => now()->subDays(10)]);

        $this->assertSame(0, $this->runCommand($org));

        $this->assertSame($this->remoteIds([$newest, $middle, $oldest]), $this->calledCases);
        Queue::assertNothingPushed();
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_chunks_and_resumes_from_cursor(): void
    {
        $org = $this->rdnOrg();
        foreach (range(1, 5) as $day) {
            $this->rdnHotlist($org, ['created_at' => now()->subDays($day)]);
        }

        $this->assertSame(0, $this->runCommand($org, ['--chunk' => 2, '--max' => 3]));
        $this->assertCount(3, $this->calledCases);
        $this->assertTrue(Cache::has($this->cursorKey($org)));

        $this->assertSame(0, $this->runCommand($org, ['--chunk' => 2]));

        $this->assertCount(5, $this->calledCases);
        $this->assertCount(5, array_unique($this->calledCases));
        $this->assertFalse(Cache::has($this->cursorKey($org)));
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_writes_results_inline_and_continues_past_a_failing_hotlist(): void
    {
        $org = $this->rdnOrg();
        $this->branch($org, 101, 'Tampa Office');

        $exact = $this->rdnHotlist($org, ['remote_order_id' => 'EXACT-1', 'created_at' => now()->subDays(1)]);
        $boom = $this->rdnHotlist($org, ['remote_order_id' => 'BOOM-1', 'created_at' => now()->subDays(2)]);
        $unknown = $this->rdnHotlist($org, ['remote_order_id' => 'UNKNOWN-1', 'created_at' => now()->subDays(3)]);
        $transport = $this->rdnHotlist($org, ['remote_order_id' => 'TRANSPORT-1', 'created_at' => now()->subDays(4)]);

        $this->assertSame(0, $this->runCommand($org));

        Queue::assertNothingPushed();
        $this->assertStringContainsString('walked 4, processed 3, failed 1', Artisan::output());

        $this->assertSame(101, json_decode($this->rawJson($exact), true)['branch_id']);

        $unknownJson = json_decode($this->rawJson($unknown), true);
        $this->assertNull($unknownJson['branch_id']);
        $this->assertSame(1, $unknownJson[GetOrgHotlistCaseInfo::ATTEMPTS_KEY]);

        foreach ([$transport, $boom] as $hotlist) {
            $this->assertFalse(
                DB::connection('zod')->table('public.hotlist_json')->where('hotlist_id', $hotlist->id)->exists(),
                "hotlist_json written for {$hotlist->remote_order_id}",
            );
        }

        $this->assertSame(4, RateLimiter::attempts(md5('rdn-case-info'.'rdn-case-info:'.$org->id)));
        $this->assertSame(0, RateLimiter::attempts('rdn-api:'.$org->id));
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_waits_on_the_jobs_rdn_case_info_limiter(): void
    {
        $this->freezeTime();
        Sleep::fake(syncWithCarbon: true);

        $org = $this->rdnOrg();
        $org->meta()->create(['properties' => ['rdn_rate_limit' => 2]]);
        $hotlists = [
            $this->rdnHotlist($org, ['created_at' => now()->subDays(1)]),
            $this->rdnHotlist($org, ['created_at' => now()->subDays(2)]),
            $this->rdnHotlist($org, ['created_at' => now()->subDays(3)]),
        ];

        $this->assertSame(0, $this->runCommand($org));

        $this->assertSame($this->remoteIds($hotlists), $this->calledCases);
        Sleep::assertSleptTimes(1);
        $this->assertSame(1, RateLimiter::attempts(md5('rdn-case-info'.'rdn-case-info:'.$org->id)));
        Queue::assertNothingPushed();
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_refuses_when_kill_switch_off(): void
    {
        config(['services.rdn.case_info_enabled' => false]);
        $org = $this->rdnOrg();
        $this->rdnHotlist($org);

        $this->assertSame(1, $this->runCommand($org));
        Queue::assertNothingPushed();
        $this->assertSame([], $this->calledCases);
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_refuses_org_without_rdn_api_key(): void
    {
        $org = Org::factory()->create(['rdn_api_key' => null]);
        $this->rdnHotlist($org);

        $this->assertSame(1, $this->runCommand($org));
        Queue::assertNothingPushed();
        $this->assertSame([], $this->calledCases);
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_rejects_invalid_options(): void
    {
        $org = $this->rdnOrg();
        $this->rdnHotlist($org);

        foreach ([
            ['--since' => null],
            ['--since' => 'not-a-date'],
            ['--until' => $this->since->subDay()->toDateString()],
            ['--chunk' => 0],
            ['--max' => -1],
            ['--dry-run' => 'abc'],
        ] as $options) {
            $this->assertSame(1, $this->runCommand($org, $options), json_encode($options));
        }

        Queue::assertNothingPushed();
        $this->assertSame([], $this->calledCases);
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_rejects_dates_not_in_y_m_d_format(): void
    {
        $org = $this->rdnOrg();
        $this->rdnHotlist($org);

        $jsonBefore = DB::connection('zod')->table('public.hotlist_json')->count();

        foreach (['--since', '--until'] as $flag) {
            foreach (['09-01-2026', '2026/09/01', '2026-09-01 10:00', '2026-9-1', '2026-02-31', 'not-a-date'] as $value) {
                $this->assertSame(1, $this->runCommand($org, [$flag => $value]), "{$flag}={$value}");
                $this->assertStringContainsString('Y-m-d', Artisan::output(), "{$flag}={$value}");
            }
        }

        $this->assertSame([], $this->calledCases);
        Queue::assertNothingPushed();
        $this->assertSame($jsonBefore, DB::connection('zod')->table('public.hotlist_json')->count());
        $this->assertFalse(Cache::has($this->cursorKey($org)));
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_dry_run_writes_nothing_and_reports_per_bucket(): void
    {
        config(['services.datadog_forwarding' => true]);
        $statsd = $this->spy(DogStatsd::class);

        $org = $this->rdnOrg();
        $this->branch($org, 101, 'Tampa Office');
        $this->branch($org, 202, 'Atlanta');

        // <30d gets the remainder: 3 samples. 30-180d and >180d get one each.
        $samples = [
            $this->rdnHotlist($org, ['remote_order_id' => 'EXACT', 'created_at' => now()->subDays(5)]),
            $this->rdnHotlist($org, ['remote_order_id' => 'NORMALIZED', 'created_at' => now()->subDays(10)]),
            $this->rdnHotlist($org, ['remote_order_id' => 'TRANSPORT', 'created_at' => now()->subDays(15)]),
            $unknown = $this->rdnHotlist($org, ['remote_order_id' => 'UNKNOWN', 'created_at' => now()->subDays(90)]),
            $this->rdnHotlist($org, ['remote_order_id' => 'EMPTY', 'created_at' => now()->subDays(400)]),
        ];

        // A prior miss below the ceiling keeps the row eligible; the job would bump it to 2.
        $this->hotlistJson($unknown, [GetOrgHotlistCaseInfo::ATTEMPTS_KEY => 1]);
        $unknownJsonBefore = $this->rawJson($unknown);

        $this->bindRdnStub();
        $jsonBefore = DB::connection('zod')->table('public.hotlist_json')->count();

        $this->assertSame(0, $this->runCommand($org, ['--dry-run' => 5]));
        $output = Artisan::output();

        Queue::assertNothingPushed();
        $this->assertSame($jsonBefore, DB::connection('zod')->table('public.hotlist_json')->count());
        $this->assertFalse(Cache::has($this->cursorKey($org)));
        $statsd->shouldNotHaveReceived('increment');

        $this->assertSame($unknownJsonBefore, $this->rawJson($unknown));
        $this->assertSame(1, json_decode($unknownJsonBefore, true)[GetOrgHotlistCaseInfo::ATTEMPTS_KEY]);

        // The dry run never took the job's uniqueness lock for any sample.
        $uniqueLock = new UniqueLock(app(CacheRepository::class));
        foreach ($samples as $hotlist) {
            $job = new GetOrgHotlistCaseInfo(orgId: $org->id, org: $org, hotlistId: $hotlist->id, hotlist: $hotlist);
            $this->assertTrue($uniqueLock->acquire($job), "lock held for {$hotlist->remote_order_id}");
            $uniqueLock->release($job);
        }

        $this->assertMatchesRegularExpression('/<30d\s*\|\s*3\s*\|\s*2\s*\|\s*0\s*\|\s*0\s*\|\s*1\s*\|/', $output);
        $this->assertMatchesRegularExpression('/30-180d\s*\|\s*1\s*\|\s*0\s*\|\s*0\s*\|\s*1\s*\|\s*0\s*\|/', $output);
        $this->assertMatchesRegularExpression('/>180d\s*\|\s*1\s*\|\s*0\s*\|\s*1\s*\|\s*0\s*\|\s*0\s*\|/', $output);
        $this->assertMatchesRegularExpression('/Nowhere Depot\s*\|\s*1\s*\|/', $output);
        $this->assertStringContainsString('Tampa Office', $output);
        $this->assertStringContainsString('Atlanta', $output);
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_bare_dry_run_samples_30_and_never_runs_live(): void
    {
        config(['services.datadog_forwarding' => true]);
        $statsd = $this->spy(DogStatsd::class);

        $org = $this->rdnOrg();

        // 11 in <30d: a sample of 30 takes 10 of them, so the table pins the default of 30.
        foreach (range(1, 11) as $day) {
            $this->rdnHotlist($org, ['remote_order_id' => "TRANSPORT-{$day}", 'created_at' => now()->subDays($day)]);
        }

        $jsonBefore = DB::connection('zod')->table('public.hotlist_json')->count();

        // The string form is tokenized like a real CLI call (runCommand() drops null options,
        // so it cannot pass a bare flag). Keep --dry-run last: a following token would be read as its value.
        $exitCode = Artisan::call(
            "app:rdn-backfill-branches {$org->id} --since={$this->since->toDateString()} --until={$this->until->toDateString()} --dry-run",
        );
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertMatchesRegularExpression('/<30d\s*\|\s*10\s*\|/', $output);
        $this->assertMatchesRegularExpression('/30-180d\s*\|\s*0\s*\|/', $output);
        $this->assertMatchesRegularExpression('/>180d\s*\|\s*0\s*\|/', $output);
        $this->assertStringNotContainsString('walked', $output);
        $this->assertStringNotContainsString('Chunk:', $output);

        $this->assertSame($jsonBefore, DB::connection('zod')->table('public.hotlist_json')->count());
        $this->assertFalse(Cache::has($this->cursorKey($org)));
        Queue::assertNothingPushed();
        $statsd->shouldNotHaveReceived('increment');

        // The live loop hits this bucket once per row; the dry run never does.
        $this->assertSame(0, RateLimiter::attempts(md5('rdn-case-info'.'rdn-case-info:'.$org->id)));
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_dry_run_classification_matches_job_outcomes(): void
    {
        $org = $this->rdnOrg();
        $this->branch($org, 101, 'Tampa Office');
        $this->bindRdnStub();

        $command = app(RdnBackfillBranches::class);
        $classify = new \ReflectionMethod($command, 'classify');

        foreach (['EXACT', 'NORMALIZED', 'UNKNOWN', 'EMPTY', 'TRANSPORT', 'DENIED'] as $case) {
            $seen = [];
            $this->mock(DogStatsd::class, function (MockInterface $mock) use (&$seen) {
                $mock->shouldReceive('increment')->andReturnUsing(function (string $metric) use (&$seen) {
                    $seen[] = $metric;
                });
            });
            config(['services.datadog_forwarding' => true]);

            $forJob = $this->rdnHotlist($org, ['remote_order_id' => $case.'-job']);
            (new GetOrgHotlistCaseInfo(
                orgId: $org->id,
                org: $org,
                hotlistId: $forJob->id,
                hotlist: $forJob,
                client: $this->stubSoapClient(),
            ))->handle();

            $unmatched = [];
            $dryRun = $classify->invokeArgs($command, [$org, $this->rdnHotlist($org, ['remote_order_id' => $case.'-dry']), &$unmatched]);

            $this->assertSame(['rdn_case_info.'.$dryRun], $seen, $case);
        }
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_a_case_refusal_is_sealed_inline_and_not_walked_again(): void
    {
        $org = $this->rdnOrg();

        $denied = $this->rdnHotlist($org, ['remote_order_id' => 'DENIED-1', 'created_at' => now()->subDays(1)]);
        $transport = $this->rdnHotlist($org, ['remote_order_id' => 'TRANSPORT-1', 'created_at' => now()->subDays(2)]);

        $this->assertSame(0, $this->runCommand($org));

        $this->assertStringContainsString('walked 2, processed 2, failed 0', Artisan::output());

        $deniedJson = json_decode($this->rawJson($denied), true);
        $this->assertSame(GetOrgHotlistCaseInfo::maxLookupAttempts(), $deniedJson[GetOrgHotlistCaseInfo::ATTEMPTS_KEY]);
        $this->assertNull($deniedJson['branch_id']);

        $this->assertFalse(
            DB::connection('zod')->table('public.hotlist_json')->where('hotlist_id', $transport->id)->exists(),
        );

        // The refusal is sealed; the transport failure is not, so only it is walked again.
        $this->calledCases = [];

        $this->assertSame(0, $this->runCommand($org));

        $this->assertSame(['TRANSPORT-1'], $this->calledCases);
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_dry_run_counts_a_case_refusal_as_a_miss_and_writes_nothing(): void
    {
        config(['services.datadog_forwarding' => true]);
        $statsd = $this->spy(DogStatsd::class);

        $org = $this->rdnOrg();
        $this->rdnHotlist($org, ['remote_order_id' => 'DENIED', 'created_at' => now()->subDays(5)]);

        $jsonBefore = DB::connection('zod')->table('public.hotlist_json')->count();

        $this->assertSame(0, $this->runCommand($org, ['--dry-run' => 1]));

        $this->assertMatchesRegularExpression('/<30d\s*\|\s*1\s*\|\s*0\s*\|\s*1\s*\|\s*0\s*\|\s*0\s*\|/', Artisan::output());
        $this->assertSame($jsonBefore, DB::connection('zod')->table('public.hotlist_json')->count());
        Queue::assertNothingPushed();
        $statsd->shouldNotHaveReceived('increment');
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_the_api_key_is_never_printed_by_a_live_or_dry_run(): void
    {
        $fakeKey = 'fake-dev2934-key-never-printed';

        $logged = [];

        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $org = Org::factory()->create(['rdn_api_key' => $fakeKey]);
        $this->branch($org, 101, 'Tampa Office');

        // One per dry-run bucket, so the sample of 3 reaches every case.
        $this->rdnHotlist($org, ['remote_order_id' => 'DENIED', 'created_at' => now()->subDays(5)]);
        $this->rdnHotlist($org, ['remote_order_id' => 'TRANSPORT', 'created_at' => now()->subDays(60)]);
        $this->rdnHotlist($org, ['remote_order_id' => 'EXACT', 'created_at' => now()->subDays(250)]);

        $this->assertSame(0, $this->runCommand($org, ['--dry-run' => 3]));
        $this->assertStringNotContainsString($fakeKey, Artisan::output());

        $this->assertSame(0, $this->runCommand($org));
        $this->assertStringNotContainsString($fakeKey, Artisan::output());

        // The refusal and the transport failure are logged by RDN::call(), so an empty
        // capture would mean the listener is not live and the loop below proves nothing.
        $this->assertNotEmpty($logged);

        foreach ($logged as $event) {
            $this->assertStringNotContainsString($fakeKey, $event->message.json_encode($event->context));
        }
    }

    private function runCommand(Org $org, array $options = []): int
    {
        $options = array_merge([
            '--since' => $this->since->toDateString(),
            '--until' => $this->until->toDateString(),
        ], $options);

        return Artisan::call('app:rdn-backfill-branches', array_merge(
            ['org' => $org->id],
            array_filter($options, fn ($value) => $value !== null),
        ));
    }

    #[Group('commands')]
    #[Group('rdn')]
    public function test_cursor_key_distinguishes_windows_by_time_of_day(): void
    {
        $since = CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC');

        $this->assertNotSame(
            RdnBackfillBranches::cursorKey(1, $since, CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC')),
            RdnBackfillBranches::cursorKey(1, $since, CarbonImmutable::parse('2026-06-01 12:00:00', 'UTC')),
        );
        $this->assertSame(
            'rdn-backfill-branches:1:2026-01-01T000000Z:open',
            RdnBackfillBranches::cursorKey(1, $since, null),
        );
    }

    private function rawJson(Hotlist $hotlist): string
    {
        return (string) DB::connection('zod')->table('public.hotlist_json')->where('hotlist_id', $hotlist->id)->value('json');
    }

    private function cursorKey(Org $org): string
    {
        return RdnBackfillBranches::cursorKey($org->id, $this->since, $this->until);
    }

    /**
     * @param  list<Hotlist>  $hotlists
     * @return list<string>
     */
    private function remoteIds(array $hotlists): array
    {
        return array_map(fn (Hotlist $hotlist): string => (string) $hotlist->remote_order_id, $hotlists);
    }

    private function rdnOrg(): Org
    {
        return Org::factory()->create(['rdn_api_key' => fake()->uuid()]);
    }

    private function rdnHotlist(Org $org, array $attributes = [], array $clickhouse = [], bool $mirror = true): Hotlist
    {
        $hotlist = Hotlist::factory()->create(array_merge([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
            'status' => HotlistStatusEnum::Open->value,
        ], $attributes));

        if ($mirror) {
            $this->mirrorHotlist($hotlist, $clickhouse);
        }

        return $hotlist;
    }

    /**
     * Copies the Postgres row into zod.public_hotlist, the CDC replica the command reads.
     * The raw Postgres strings keep the microseconds. $overrides sets a replica-only state
     * (another status, version or tombstone); call it again with a higher _peerdb_version
     * for a later CDC version of the same row.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function mirrorHotlist(Hotlist $hotlist, array $overrides = []): void
    {
        $row = DB::connection('zod')->table('public.hotlist')->where('id', $hotlist->id)->first();

        $this->chHotlist(array_merge([
            'id' => (int) $row->id,
            'org_id' => (int) $row->org_id,
            'integration_id' => (int) $row->integration_id,
            'remote_order_id' => $row->remote_order_id,
            'status' => (int) $row->status,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ], $overrides));
    }

    /**
     * A replica-only row: no Postgres twin. Pass every column the command reads.
     *
     * @param  array<string, mixed>  $row
     */
    private function chHotlist(array $row): void
    {
        DB::connection('public_clickhouse')->table('public_hotlist')->insert(array_merge([
            '_peerdb_is_deleted' => 0,
            '_peerdb_version' => 1,
        ], $row));
    }

    /** @param  array<string, mixed>  $row */
    private function chHotlistMeta(array $row): void
    {
        DB::connection('public_clickhouse')->table('public_hotlist_meta')->insert(array_merge([
            '_peerdb_is_deleted' => 0,
            '_peerdb_version' => 1,
        ], $row));
    }

    private function branch(Org $org, int $branchId, string $name): Branch
    {
        return Branch::factory()->create(['org_id' => $org->id, 'branch_id' => $branchId, 'branch_name' => $name]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function repoDate(Hotlist $hotlist, string $date, array $overrides = []): HotlistMeta
    {
        $meta = HotlistMeta::factory()->forHotlist($hotlist)->create(['repo_date' => $date]);

        $this->chHotlistMeta(array_merge([
            'id' => (int) $meta->id,
            'hotlist_id' => $hotlist->id,
            'repo_date' => $date,
        ], $overrides));

        return $meta;
    }

    private function hotlistJson(Hotlist $hotlist, array $json): void
    {
        DB::connection('zod')->table('public.hotlist_json')->updateOrInsert(
            ['hotlist_id' => $hotlist->id],
            ['json' => json_encode($json), 'created_at' => now(), 'updated_at' => now()],
        );
    }

    private function bindRdnStub(): void
    {
        $this->app->bind(RDN::class, fn ($app, array $params) => new RDN($params['org'], $this->stubSoapClient()));
    }

    /**
     * Answers by case id: an exact branch name, a normalized-only variant, an unknown
     * name, an empty answer, a per-case API key refusal (DENIED), a transport failure, and
     * an \Error (which RDN::call() does not catch) to simulate a per-row failure. Records
     * every case id it is asked about.
     */
    private function stubSoapClient(): \SoapClient
    {
        return $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('__soapCall')->andReturnUsing(function (string $name, array $args) {
                $case = (string) $args['case'];
                $this->calledCases[] = $case;

                $branch = match (explode('-', $case)[0]) {
                    'EXACT' => 'Tampa Office',
                    'NORMALIZED' => '  tampa-office. ',
                    'UNKNOWN' => 'Nowhere Depot',
                    'EMPTY' => null,
                    'BOOM' => throw new \Error('boom'),
                    'DENIED' => throw new \SoapFault('SOAP-ENV:Server', 'This API key is not authorized to access this case'),
                    default => throw new \SoapFault('HTTP', 'Could not connect to host'),
                };

                return $branch === null ? null : (object) ['item' => [(object) ['vendor_branch_name' => $branch]]];
            });
            $mock->shouldReceive('__getLastResponse')->andReturn(null);
        });
    }
}
