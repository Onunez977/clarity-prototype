<?php

declare(strict_types=1);

/**
 * Isolated-cloud / local seed for DEV-2546 Playwright (Hits metric cards).
 *
 * Postgres hits for a dedicated repo org (customer Hits page path) plus a
 * small clarity.hits_hot set for the impersonation / ClickHouse branch.
 * Refuses production. Prints one JSON object on stdout.
 *
 * Modes:
 *   seed
 *   fail-marker   — overwrite cached hit-metric aggregates with the failure marker
 *   clear-marker  — delete file-cache entries touched by fail-marker
 */

use App\Enums\HitTypeEnum;
use App\Enums\HotlistStatusEnum;
use App\Filament\Auth\CustomLoginPage;
use App\Models\Hit;
use App\Models\Hotlist;
use App\Models\Integrations;
use App\Models\Kit;
use App\Models\Org;
use App\Models\Scan;
use App\Models\ScanMetaData;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use InsightLPR\BlackAdam\BlackAdam;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;
use InsightLPR\BlackAdam\Enum\OrgType;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Spatie\Permission\Models\Role;

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->isProduction()) {
    fwrite(STDERR, "DEV-2546 seed refuses to run in production.\n");
    exit(1);
}

$command = $argv[1] ?? 'seed';

if ($command === 'fail-marker') {
    $touched = rewriteHitMetricCache(true);
    echo json_encode(['marked' => $touched], JSON_THROW_ON_ERROR).PHP_EOL;
    exit($touched > 0 ? 0 : 2);
}

if ($command === 'clear-marker') {
    $touched = rewriteHitMetricCache(false);
    echo json_encode(['cleared' => $touched], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($command === 'clear-login-limit') {
    $component = CustomLoginPage::class;

    foreach (['127.0.0.1', '::1'] as $ip) {
        RateLimiter::clear('livewire-rate-limiter:'.sha1($component.'|authenticate|'.$ip));
    }

    echo json_encode(['cleared' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($command !== 'seed') {
    fwrite(STDERR, "Unknown mode {$command}. Use seed|fail-marker|clear-marker|clear-login-limit.\n");
    exit(1);
}

$adminEmail = getenv('E2E_ADMIN_EMAIL') ?: 'qa-dev-2546-admin@tester.com';
$orgName = 'DEV-2546 Hits Metrics';

advanceUsersSequence();

if (! Role::query()->where('name', RoleEnum::ADMIN->value)->where('guard_name', 'web')->exists()) {
    Artisan::call('app:seed-permissions');
}

$org = Org::query()->where('name', $orgName)->first();

if (! $org) {
    $org = Org::factory()->create([
        'name' => $orgName,
        'type' => OrgType::Repo->value,
        'enable_genetech_hotlist' => true,
    ]);
}

$admin = User::query()->where('email', $adminEmail)->first();

if (! $admin) {
    $admin = User::factory()->forOrg($org)->create([
        'email' => $adminEmail,
        'name' => 'DEV-2546 Hits Admin',
    ]);
} elseif ((int) $admin->org_id !== (int) $org->id) {
    $admin->update(['org_id' => $org->id]);
    $admin = $admin->fresh();
}

$dti = $admin->dti ?? [];
$dti['timeZone'] = 'UTC';
$admin->update(['dti' => $dti]);
$admin = $admin->fresh();

setPermissionsTeamId($org->id);
$admin->assignRole(RoleEnum::ADMIN->value);
$admin->givePermissionTo(PermissionEnum::ViewAny_Hit->value);

$kit = Kit::query()->where('org_id', $org->id)->where('name', 'DEV-2546 Kit')->first()
    ?? Kit::factory()->forOrg($org)->create(['name' => 'DEV-2546 Kit']);

wipeOrgHits($org, $kit);

$integrationId = (int) (Integrations::query()->where('id', '!=', IntegrationEnum::NICB->value)->value('id')
    ?? Integrations::factory()->create()->id);

$plates = [
    'live' => 'D2546LIV',
    'historical' => 'D2546HIS',
    'stagingLive' => 'D2546STL',
    'stagingHistorical' => 'D2546STH',
    'probable' => 'D2546PRB',
    'missed' => 'D2546MIS',
    'spotted' => 'D2546SPT',
    'old' => 'D2546OLD',
    'held' => 'D2546HLD',
];

$vins = [
    'live' => 'D2546VINLIVE00001',
    'historical' => 'D2546VINHIST00001',
    'probable' => 'D2546VINPROB00001',
];

$orderIds = [
    'live' => '2546001',
    'historical' => '2546002',
    'probable' => '2546003',
];

$live = createLiveHit($kit, $org, $integrationId, $plates['live'], $vins['live'], $orderIds['live']);

for ($i = 1; $i <= 46; $i++) {
    Scan::factory()->for($kit)->create([
        'ocr' => $plates['live'],
        'scanned_at' => now()->subMinutes($i),
        'is_visible' => true,
    ]);
}

$historical = createHistoricalHit($kit, $org, $integrationId, $plates['historical'], $vins['historical'], $orderIds['historical']);
$stagingLive = retitle(createLiveHit($kit, $org, $integrationId, $plates['stagingLive'], 'D2546VINSTL000001', '2546004'), HitTypeEnum::Staging_Live->value);
$stagingHistorical = retitle(createLiveHit($kit, $org, $integrationId, $plates['stagingHistorical'], 'D2546VINSTH000001', '2546005'), HitTypeEnum::Staging_Historical->value);
$probable = retitle(createLiveHit($kit, $org, $integrationId, $plates['probable'], $vins['probable'], $orderIds['probable']), HitTypeEnum::Probable_Match->value);
retitle(createLiveHit($kit, $org, $integrationId, $plates['missed'], 'D2546VINMISS00001', '2546006'), HitTypeEnum::Missed_Opportunity->value);
retitle(createLiveHit($kit, $org, $integrationId, $plates['spotted'], 'D2546VINSPOT00001', '2546007'), HitTypeEnum::Missed_Opportunity_Historical->value);

$old = createLiveHit($kit, $org, $integrationId, $plates['old'], 'D2546VINOLD000001', '2546008');
DB::connection('zod')->table('hits')->where('id', $old['hit']->id)->update([
    'created_at' => now()->subDays(3),
]);

$held = createLiveHit($kit, $org, $integrationId, $plates['held'], 'D2546VINHLD000001', '2546009');
$held['hotlist']->update(['status' => HotlistStatusEnum::Hold->value]);

// The Hit Date end default drops seconds, so a hit created during the current
// minute can fall after that bound. Park today's rows in the previous minute.
$visibleAt = now()->startOfMinute()->subMinute();
DB::connection('zod')->table('hits')->where('kit_id', $kit->id)->where('id', '!=', $old['hit']->id)->update([
    'created_at' => $visibleAt,
]);
DB::connection('zod')->table('hits')->where('id', $historical['hit']->id)->update([
    'created_at' => $visibleAt->copy()->addSeconds(30),
]);

$client = Org::query()->find($historical['hotlist']->client_org_id);
$lienholder = Org::query()->find($historical['hotlist']->lienholder_org_id);

cache()->forget('org-kits:'.$org->id.'-all');
cache()->forget('org-kits:'.$org->id.'-active');
cache()->forget('org-shared-kits:'.$org->id);

$tz = $admin->timezone;
$todayStart = convertToUtc(now($tz)->copy()->startOfDay())->toDateTimeString();
$nowEnd = now('UTC')->copy()->addMinute()->toDateTimeString();
$last7Start = convertToUtc(now($tz)->copy()->subDays(7)->startOfDay())->toDateTimeString();

$chSeeded = seedClickhouse($kit, $org, $admin, $client, $lienholder);
cache()->forget('hotlist-clients:'.$org->id);
cache()->forget('hotlist-lienholders:'.$org->id);

$impersonateUrl = BlackAdam::generateSignedUrl(url('/auth/impersonate'), [
    'user_id' => $admin->id,
    'impersonator' => [
        'id' => 1,
        'name' => 'QA Runner',
    ],
]);

$today = countsFor($kit->id, $todayStart, $nowEnd);
$last7d = countsFor($kit->id, $last7Start, $nowEnd);

foreach (['L', 'H', 'S', 'Z'] as $hitType) {
    if (($today[$hitType]['total'] ?? 0) < 1 || ($last7d[$hitType]['total'] ?? 0) < 1) {
        fwrite(STDERR, "DEV-2546 seed missing {$hitType} in the {$tz} window. today=".json_encode($today).' last7d='.json_encode($last7d).PHP_EOL);
        exit(1);
    }
}

if ($today['L']['unique'] >= $today['L']['total'] || $last7d['L']['total'] <= $today['L']['total']) {
    fwrite(STDERR, 'DEV-2546 seed did not separate today vs last-7d live totals. today='.json_encode($today).' last7d='.json_encode($last7d).PHP_EOL);
    exit(1);
}

$payload = [
    'adminEmail' => $admin->email,
    'adminUserId' => $admin->id,
    'orgId' => $org->id,
    'kitId' => $kit->id,
    'timezone' => $tz,
    'clientName' => $client?->name,
    'lienholderName' => $lienholder?->name,
    'plates' => $plates,
    'vins' => $vins,
    'orderIds' => $orderIds,
    'today' => $today,
    'last7d' => $last7d,
    'chSeeded' => $chSeeded,
    'impersonateUrl' => $impersonateUrl,
];

echo json_encode($payload, JSON_THROW_ON_ERROR).PHP_EOL;

function advanceUsersSequence(): void
{
    $maxId = (int) (User::withTrashed()->max('id') ?? 0);

    if ($maxId < 1) {
        return;
    }

    DB::connection('platform')->statement(
        "SELECT setval(pg_get_serial_sequence('users', 'id'), ?)",
        [$maxId],
    );
}

/**
 * @return array{hotlist: Hotlist, scan: Scan, hit: Hit}
 */
function createLiveHit(Kit $kit, Org $org, int $integrationId, string $plate, string $vin, string $orderId): array
{
    $hotlist = Hotlist::factory()->create([
        'org_id' => $org->id,
        'plate' => $plate,
        'vin' => $vin,
        'status' => HotlistStatusEnum::Open->value,
        'order_type' => 1,
        'is_staged' => false,
        'integration_id' => $integrationId,
        'remote_order_id' => $orderId,
    ]);

    $scan = Scan::factory()->for($kit)->create([
        'ocr' => $plate,
        'scanned_at' => now(),
        'is_visible' => true,
    ]);

    $hit = Hit::query()->where('scan_id', $scan->id)->where('hotlist_id', $hotlist->id)->where('hit_type', HitTypeEnum::Live->value)->first();

    if (! $hit) {
        fwrite(STDERR, "DEV-2546 seed: live hit was not created for plate {$plate}.\n");
        exit(1);
    }

    return ['hotlist' => $hotlist, 'scan' => $scan, 'hit' => $hit];
}

/**
 * @return array{hotlist: Hotlist, scan: Scan, hit: Hit}
 */
function createHistoricalHit(Kit $kit, Org $org, int $integrationId, string $plate, string $vin, string $orderId): array
{
    $scan = Scan::factory()->for($kit)->create([
        'ocr' => $plate,
        'scanned_at' => now()->subHour(),
        'is_visible' => true,
    ]);

    $hotlist = Hotlist::factory()->create([
        'org_id' => $org->id,
        'plate' => $plate,
        'vin' => $vin,
        'status' => HotlistStatusEnum::Open->value,
        'order_type' => 1,
        'is_staged' => false,
        'integration_id' => $integrationId,
        'remote_order_id' => $orderId,
    ]);

    $hit = Hit::query()->where('scan_id', $scan->id)->where('hotlist_id', $hotlist->id)->first();

    if (! $hit) {
        fwrite(STDERR, "DEV-2546 seed: historical hit was not created for plate {$plate}.\n");
        exit(1);
    }

    return ['hotlist' => $hotlist, 'scan' => $scan, 'hit' => $hit];
}

/**
 * @param  array{hotlist: Hotlist, scan: Scan, hit: Hit}  $row
 * @return array{hotlist: Hotlist, scan: Scan, hit: Hit}
 */
function retitle(array $row, string $hitType): array
{
    DB::connection('zod')->table('hits')->where('id', $row['hit']->id)->update(['hit_type' => $hitType]);
    $row['hit']->refresh();

    return $row;
}

function wipeOrgHits(Org $org, Kit $kit): void
{
    $hotlistIds = Hotlist::query()->where('org_id', $org->id)->pluck('id');

    if ($hotlistIds->isNotEmpty()) {
        Hit::query()->whereIn('hotlist_id', $hotlistIds)->orWhere('kit_id', $kit->id)->delete();
        Hotlist::query()->whereIn('id', $hotlistIds)->delete();
    } else {
        Hit::query()->where('kit_id', $kit->id)->delete();
    }

    $scanIds = Scan::query()->where('kit_id', $kit->id)->pluck('id');

    if ($scanIds->isNotEmpty()) {
        ScanMetaData::query()->whereIn('scan_id', $scanIds)->delete();
        Scan::query()->whereIn('id', $scanIds)->delete();
    }
}

/**
 * @return array<string, array{unique: int, total: int}>
 */
function countsFor(int $kitId, string $start, string $end): array
{
    $rows = DB::connection('zod')->table('hits')
        ->join('scans', 'scans.id', '=', 'hits.scan_id')
        ->join('hotlist', 'hotlist.id', '=', 'hits.hotlist_id')
        ->join('scans_metadata', 'scans_metadata.scan_id', '=', 'hits.scan_id')
        ->where('hits.kit_id', $kitId)
        ->where('hits.hit_type', '!=', 'O')
        ->where('hotlist.status', HotlistStatusEnum::Open->value)
        ->where('hotlist.integration_id', '!=', IntegrationEnum::NICB->value)
        ->whereBetween('hits.created_at', [$start, $end])
        ->where(function ($query): void {
            $query->whereNull('scans_metadata.meta->disposition')
                ->orWhere('scans_metadata.meta->disposition', 'New Hit')
                ->orWhere('scans_metadata.meta->disposition', 'Accessible');
        })
        ->groupBy('hits.hit_type')
        ->selectRaw('hits.hit_type as hit_type, count(distinct scans.ocr) as unique_hits, count(*) as total_hits')
        ->get();

    $counts = [];

    foreach ($rows as $row) {
        $counts[trim((string) $row->hit_type)] = [
            'unique' => (int) $row->unique_hits,
            'total' => (int) $row->total_hits,
        ];
    }

    return $counts;
}

function seedClickhouse(Kit $kit, Org $org, User $admin, ?Org $client, ?Org $lienholder): bool
{
    try {
        $connection = DB::connection('clarity_clickhouse');
        $connection->statement("ALTER TABLE clarity.hits_hot DELETE WHERE ocr LIKE 'D2546CH%'");
        $connection->statement('ALTER TABLE clarity.hotlist DELETE WHERE id = 2546200');

        if ($client) {
            $at = now()->toDateTimeString();
            $connection->table('hotlist')->insert([
                'id' => 2546200,
                'org_id' => $org->id,
                'client_org_id' => $client->id,
                'client_org_name' => $client->name,
                'lienholder_org_id' => $lienholder?->id,
                'lienholder_org_name' => $lienholder?->name,
                'is_staged' => false,
                'status' => HotlistStatusEnum::Open->value,
                'plate' => 'D2546HIS',
                'created_at' => $at,
                'updated_at' => $at,
                '_peerdb_synced_at' => $at,
                '_version' => 1,
                '_peerdb_is_deleted' => 0,
            ]);
        }

        $rows = [
            ['id' => 2546101, 'ocr' => 'D2546CHL', 'hit_type' => HitTypeEnum::Live->value],
            ['id' => 2546102, 'ocr' => 'D2546CHL', 'hit_type' => HitTypeEnum::Live->value],
            ['id' => 2546103, 'ocr' => 'D2546CHH', 'hit_type' => HitTypeEnum::Historical->value],
            ['id' => 2546104, 'ocr' => 'D2546CHS', 'hit_type' => HitTypeEnum::Staging_Live->value],
            ['id' => 2546105, 'ocr' => 'D2546CHZ', 'hit_type' => HitTypeEnum::Staging_Historical->value],
        ];

        foreach ($rows as $index => $row) {
            $at = now()->subMinutes($index)->toDateTimeString();
            $connection->table('hits_hot')->insert([
                'id' => $row['id'],
                'hotlist_id' => 2546100,
                'scan_id' => $row['id'],
                'hit_type' => $row['hit_type'],
                'hit_created_at' => $at,
                'ocr' => $row['ocr'],
                'scanned_at' => $at,
                'camera_name' => 'CAM-2546',
                'scan_us_state' => 'TX',
                'kit_id' => $kit->id,
                'kit_name' => $kit->name,
                'kit_org_id' => $org->id,
                'hotlist_org_id' => $org->id,
                'hotlist_us_state' => 'TX',
                'hotlist_plate' => $row['ocr'],
                'hotlist_status' => HotlistStatusEnum::Open->value,
                'hotlist_vin' => 'D2546CHVIN'.$row['id'],
                'integration_id' => 1,
                'client_org_id' => $org->id,
                'lienholder_org_id' => $org->id,
                'remote_order_id' => '2546'.$row['id'],
                '_peerdb_synced_at' => $at,
                '_peerdb_hotlist_synced_at' => $at,
                '_version' => 1,
                '_peerdb_is_deleted' => 0,
            ]);
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'DEV-2546 ClickHouse seed skipped: '.$exception->getMessage()."\n");

        return false;
    }

    return true;
}

function rewriteHitMetricCache(bool $markFailed): int
{
    $directory = storage_path('framework/cache/data');

    if (! is_dir($directory)) {
        return 0;
    }

    $touched = 0;
    $marker = serialize('hit-metric-cards:failed');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $raw = file_get_contents($file->getPathname());

        if ($raw === false || strlen($raw) < 11) {
            continue;
        }

        $payload = substr($raw, 10);
        $isAggregate = str_contains($payload, 'unique') && str_contains($payload, 'total') && str_contains($payload, 's:1:"L"');
        $isMarker = str_contains($payload, 'hit-metric-cards:failed');

        if ($markFailed && ! $isAggregate) {
            continue;
        }

        if (! $markFailed && ! $isMarker) {
            continue;
        }

        if ($markFailed) {
            $expiry = substr($raw, 0, 10);
            file_put_contents($file->getPathname(), $expiry.$marker);
        } else {
            unlink($file->getPathname());
        }

        $touched++;
    }

    return $touched;
}
