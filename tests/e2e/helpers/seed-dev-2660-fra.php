<?php

declare(strict_types=1);

/**
 * Isolated-cloud seed for DEV-2660 Company Metrics LPR Repossessions.
 *
 * Builds the FREEZE A–G fixture on the logged-in agent's org and checks
 * LprRepossessionsQuery before Playwright reads the card. Refuses production
 * and any ClickHouse host that is not loopback. Prints one JSON object.
 */

use App\Clickhouse\Queries\LprRepossessionsQuery;
use App\Models\Org;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\BillingEventType;
use InsightLPR\BlackAdam\Enum\RoleEnum;

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->isProduction()) {
    fwrite(STDERR, "DEV-2660 seed refuses to run in production.\n");
    exit(1);
}

$chHost = (string) config('database.connections.clarity_clickhouse.host');

if (! in_array($chHost, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "DEV-2660 seed refuses ClickHouse host {$chHost}.\n");
    exit(1);
}

if (config('database.connections.clarity_clickhouse.driver') !== 'clickhouse') {
    fwrite(STDERR, "DEV-2660 seed requires CLICKHOUSE_DRIVER=clickhouse.\n");
    exit(1);
}

Artisan::call('app:seed-permissions');

$email = getenv('E2E_ADMIN_EMAIL') ?: 'test@tester.com';
$user = ensureAdmin($email);
$orgId = (int) $user->org_id;
$forwarderOrgId = 26600001;

foreach ([
    'clarity.billing_claim_events',
    'clarity.hotlist',
    'zod.public_hotlist',
    'zod.public_hotlist_status_log',
] as $table) {
    DB::connection('clarity_clickhouse')->statement("TRUNCATE TABLE IF EXISTS {$table}");
}

// The refreshable hotlist MV appends first_repossessed_at from the status log
// within a minute. Stop it so a null FRA fixture stays null for the matrix.
DB::connection('clarity_clickhouse')->statement('SYSTEM STOP VIEW clarity.hotlist_incremental_mv');

$nextId = 26601000;

$claimA = Carbon::parse('2026-09-10 12:00:00', 'UTC');
$fraA = Carbon::parse('2026-09-08 00:00:00', 'UTC');
$hotlistA = seedHotlist($orgId, 'FRAINWINDOW000001', 3, 1, false);
seedClaim($forwarderOrgId, $orgId, 'FRAINWINDOW000001', $claimA);
seedStatusLog($hotlistA, 3, $fraA);
seedClarityHotlist($hotlistA, $fraA, $orgId);

$claimB = Carbon::parse('2026-08-15 12:00:00', 'UTC');
$statusB = Carbon::parse('2026-08-15 15:00:00', 'UTC');
$hotlistB = seedHotlist($orgId, 'FRANULLMISS000001', 3, 1, false);
seedClaim($forwarderOrgId, $orgId, 'FRANULLMISS000001', $claimB);
seedStatusLog($hotlistB, 3, $statusB);
seedClarityHotlist($hotlistB, null, $orgId);

$claimC = Carbon::parse('2026-09-15 12:00:00', 'UTC');
$minC = Carbon::parse('2026-09-01 12:00:00', 'UTC');
$laterC = Carbon::parse('2026-09-12 12:00:00', 'UTC');
$hotlistC = seedHotlist($orgId, 'FRAOUTWINDOW00001', 3, 1, false);
seedClaim($forwarderOrgId, $orgId, 'FRAOUTWINDOW00001', $claimC);
seedStatusLog($hotlistC, 3, $minC);
seedStatusLog($hotlistC, 3, $laterC);
seedClarityHotlist($hotlistC, $minC, $orgId);

$claimD = Carbon::parse('2026-09-12 12:00:00', 'UTC');
$fraD = Carbon::parse('2026-09-10 00:00:00', 'UTC');
$hotlistD = seedHotlist($orgId, 'FRAFINALDROP00001', 3, 1, false);
seedHotlist($orgId, 'FRAFINALDROP00001', 3, 2, true, $hotlistD);
seedClaim($forwarderOrgId, $orgId, 'FRAFINALDROP00001', $claimD);
seedStatusLog($hotlistD, 3, $fraD);
seedClarityHotlist($hotlistD, $fraD, $orgId);

$claimE = Carbon::parse('2026-09-24 12:00:00', 'UTC');
$statusE = Carbon::parse('2026-09-24 18:00:00', 'UTC');
$hotlistE = seedHotlist($orgId, 'STATUSLOGONLY0001', 3, 1, false);
seedClaim($forwarderOrgId, $orgId, 'STATUSLOGONLY0001', $claimE);
seedStatusLog($hotlistE, 3, $statusE);

// Drop any rows the MV appended before STOP took effect, then write the
// intended FRA values only. E stays absent on purpose.
DB::connection('clarity_clickhouse')->statement('TRUNCATE TABLE clarity.hotlist');

seedClarityHotlist($hotlistA, $fraA, $orgId);
seedClarityHotlist($hotlistB, null, $orgId);
seedClarityHotlist($hotlistC, $minC, $orgId);
seedClarityHotlist($hotlistD, $fraD, $orgId);
seedClarityHotlist(0, Carbon::parse('2026-09-10 00:00:00', 'UTC'), 0);

Artisan::call('cache:clear');

$september = LprRepossessionsQuery::window(
    $orgId,
    Carbon::parse('2026-09-01 00:00:00', 'UTC'),
    Carbon::parse('2026-09-30 23:59:59', 'UTC'),
);
$august = LprRepossessionsQuery::window(
    $orgId,
    Carbon::parse('2026-08-01 00:00:00', 'UTC'),
    Carbon::parse('2026-08-31 23:59:59', 'UTC'),
);
$last7 = LprRepossessionsQuery::window(
    $orgId,
    Carbon::parse('2026-09-21 00:00:00', 'UTC'),
    Carbon::parse('2026-09-28 23:59:59', 'UTC'),
);
$today = LprRepossessionsQuery::window(
    $orgId,
    Carbon::parse('2026-09-28 00:00:00', 'UTC'),
    Carbon::parse('2026-09-28 23:59:59', 'UTC'),
);
$series = LprRepossessionsQuery::monthlySeries($orgId, Carbon::parse('2026-09-28 12:00:00', 'UTC'));

$expected = [
    'september_db_repos' => 1,
    'september_deduped' => 4,
    'august_db_repos' => 0,
    'august_deduped' => 1,
    'last7_db_repos' => 0,
    'last7_deduped' => 1,
    'today_db_repos' => 0,
    'today_deduped' => 0,
    'series_september_db_repos' => 1,
    'series_august_db_repos' => 0,
];

$actual = [
    'september_db_repos' => $september['db_repos'],
    'september_deduped' => $september['deduped'],
    'august_db_repos' => $august['db_repos'],
    'august_deduped' => $august['deduped'],
    'last7_db_repos' => $last7['db_repos'],
    'last7_deduped' => $last7['deduped'],
    'today_db_repos' => $today['db_repos'],
    'today_deduped' => $today['deduped'],
    'series_september_db_repos' => $series['2026-09-01']['db_repos'] ?? null,
    'series_august_db_repos' => $series['2026-08-01']['db_repos'] ?? null,
];

$ok = $actual === $expected;

echo json_encode([
    'ok' => $ok,
    'email' => $user->email,
    'user_id' => $user->id,
    'org_id' => $orgId,
    'timezone' => $user->timezone,
    'expected' => $expected,
    'actual' => $actual,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;

exit($ok ? 0 : 2);

function ensureAdmin(string $email): User
{
    $user = User::query()->where('email', $email)->first();

    if ($user === null) {
        $org = Org::factory()->create([
            'name' => 'Test Company',
        ]);

        $user = User::factory()->forOrg($org)->create([
            'email' => $email,
            'name' => 'Admin',
        ]);
    }

    $user->dti = ['timeZone' => 'UTC'];
    $user->save();

    setPermissionsTeamId($user->org_id);
    if (! $user->hasRole(RoleEnum::ADMIN->value)) {
        $user->assignRole(RoleEnum::ADMIN->value);
    }

    return $user->fresh();
}

function seedClaim(int $forwarderOrgId, int $agentOrgId, string $vin, Carbon $ts): void
{
    global $nextId;

    $moment = $ts->clone()->utc();

    DB::connection('clarity_clickhouse')->table('clarity.billing_claim_events')->insert([
        'id' => $nextId++,
        'org_id' => $forwarderOrgId,
        'agent_org_id' => $agentOrgId,
        'vin' => $vin,
        'hotlist_id' => 999,
        'event_type' => BillingEventType::CASE_CLAIM->value,
        'success' => true,
        'is_internal' => 0,
        'event_date' => $moment->toDateString(),
        'ts' => $moment->timestamp,
        'api_key_id' => 0,
        '_peerdb_synced_at' => $moment->toDateTimeString(),
        '_peerdb_api_key_synced_at' => $moment->toDateTimeString(),
        '_version' => 1,
        '_peerdb_is_deleted' => 0,
    ]);
}

function seedHotlist(int $agentOrgId, string $vin, int $status, int $version, bool $deleted, ?int $id = null): int
{
    global $nextId;

    $id ??= $nextId++;
    $now = now()->utc()->toDateTimeString();

    DB::connection('clarity_clickhouse')->table('zod.public_hotlist')->insert([
        'id' => $id,
        'integration_id' => 1,
        'status' => $status,
        'org_id' => $agentOrgId,
        'client_org_id' => $agentOrgId,
        'order_type' => 1,
        'is_staged' => false,
        'plate' => '',
        'us_state' => '',
        'vin' => $vin,
        'vehicle_year' => '',
        'vehicle_make' => '',
        'vehicle_model' => '',
        'lookback_days' => 0,
        'created_at' => $now,
        'updated_at' => $now,
        '_peerdb_is_deleted' => $deleted ? 1 : 0,
        '_peerdb_version' => $version,
    ]);

    return $id;
}

function seedClarityHotlist(int $id, ?Carbon $firstRepossessedAt, int $orgId): void
{
    $now = now()->utc()->toDateTimeString();

    DB::connection('clarity_clickhouse')->table('clarity.hotlist')->insert([
        'id' => $id,
        'org_id' => $orgId,
        'status' => 3,
        'order_type' => 1,
        'is_staged' => false,
        'created_at' => $now,
        'updated_at' => $now,
        '_peerdb_synced_at' => $now,
        '_version' => 1,
        '_peerdb_is_deleted' => 0,
        'first_repossessed_at' => $firstRepossessedAt?->clone()->utc()->toDateTimeString(),
    ]);
}

function seedStatusLog(int $hotlistId, int $newStatus, Carbon $createdAt): void
{
    global $nextId;

    DB::connection('clarity_clickhouse')->table('zod.public_hotlist_status_log')->insert([
        'id' => $nextId++,
        'hotlist_id' => $hotlistId,
        'old_status' => 1,
        'new_status' => $newStatus,
        'created_at' => $createdAt->clone()->utc()->toDateTimeString(),
        '_peerdb_is_deleted' => 0,
        '_peerdb_version' => 1,
    ]);
}
