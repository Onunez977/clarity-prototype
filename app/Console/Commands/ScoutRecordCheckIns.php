<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Clickhouse\Queries\ScoutCheckInMatchQuery;
use App\Enums\FeatureEnum;
use App\Http\Middleware\ClarityScoutMiddleware;
use App\Models\Kit;
use App\Models\Org;
use App\Models\ScoutCheckIn;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use RuntimeException;

/**
 * Records one automated check-in per (kit, address GEO, hour) by matching each
 * Scout-enabled kit's recent GPS positions (clarity.sc_positions) against the
 * MV-backed open-order book (clarity.scout_open_addresses), via
 * {@see ScoutCheckInMatchQuery}, which elects one canonical address per geo
 * PER is_staged bucket (lowest address_id within the bucket), so a location
 * carrying both a Direct and a Staging order records one check-in on each side.
 * Rows are now STORED on that same grain — `scout_check_ins_geo_hour_side_unique`
 * on (kit_id, org_id, addr_lng, addr_lat, hour_bucket, is_staged) — so a
 * mid-hour winner change collides on the geo instead of adding a spurious
 * row. `org_id` is in the key because this loop stamps the
 * DRIVER's org, not the kit's — not because one kit spans two orgs in a cycle
 * (kit_settings keys on kit_id, so it cannot). The reachable path is a kit
 * REASSIGNED to another org's driver inside the same hour.
 * Two residual gaps remain, both accepted by explicit decision (Dalton,
 * 2026-08-25): a place carrying both a Direct and a Staging order still
 * yields two rows for one visit, one per side (AC4's grain); and
 * `address_id`/`hotlist_id` are frozen at first sighting and never
 * refreshed, so after a mid-hour side change both surviving rows can name
 * the same `address_id` — DEV-1756's derived `COUNT(*) ... WHERE
 * address_id = ?` can then report two check-ins for one address in one
 * hour. A third residual is inherent rather than chosen: the elected geo is
 * itself mutable across rebuilds, so a re-geocoded address also writes a second
 * row for one visit — see {@see ScoutCheckInMatchQuery}'s docblock for the
 * measured bound. The UI stays hidden; this is additive analytics storage only.
 *
 * The match is computed server-side in one ClickHouse statement per kit — no
 * org-wide address list crosses the PHP boundary. A hard N-slot Cache::add
 * semaphore, heartbeat-refreshed while the process runs, bounds concurrency
 * independently of cycle duration (DEV-2342 AC4). Rebuild freshness of
 * `clarity.scout_open_addresses` is owned by chlocal; this command does not
 * probe `refreshed_at`.
 *
 * Gated behind the `app.experimental.clarity_scout` flag so it's a no-op
 * until the portal itself ships.
 */
class ScoutRecordCheckIns extends Command
{
    private const SLOT_KEY_PREFIX = 'scout:record-check-ins:slot:';

    protected $signature = 'scout:record-check-ins {--since= : ISO datetime lower bound; default now-15min}';

    protected $description = 'Match each Scout kit\'s recent GPS positions against its open Direct/Staging order addresses and record a check-in per (kit, address, hour).';

    private ?string $slotKey = null;

    private ?string $slotOwner = null;

    public function handle(): int
    {
        if (! config('app.experimental.clarity_scout', false)) {
            $this->info('Clarity Scout is disabled — nothing to do.');

            return self::SUCCESS;
        }

        if (! $this->acquireConcurrencySlot()) {
            return self::SUCCESS;
        }

        try {
            return $this->recordCheckIns();
        } finally {
            $this->releaseConcurrencySlot();
        }
    }

    private function recordCheckIns(): int
    {
        $lookbackMinutes = (int) config('scout.check_ins.lookback_minutes');

        $since = $this->option('since')
            ? Carbon::parse($this->option('since'))
            : now()->subMinutes($lookbackMinutes);
        $end = now();

        $startedAt = microtime(true);
        $recorded = 0;
        $kitsProcessed = 0;
        $orgsProcessed = 0;
        $skipped = 0;

        $driversByOrg = $this->scoutEnabledDrivers()->groupBy('org_id');
        $orgs = Org::query()
            ->whereIn('id', $driversByOrg->keys()->filter()->all())
            ->get()
            ->keyBy('id');

        foreach ($driversByOrg as $orgId => $drivers) {
            $orgId = (int) $orgId;
            $orgsProcessed++;
            $stagingOrgIds = $orgs->get($orgId)?->stagingOrgIds()->toArray() ?? [];

            foreach ($drivers as $driver) {
                $this->heartbeatConcurrencySlot();

                $kit = $driver->kit;

                if (! $kit || ! $kit->kit_mapping) {
                    $skipped++;

                    continue;
                }

                try {
                    $recorded += $this->recordCheckInsForKit($kit, $orgId, $stagingOrgIds, $since, $end);
                    $kitsProcessed++;
                } catch (\Throwable $e) {
                    report($e);
                    Log::warning('scout:record-check-ins: kit failed', [
                        'kit_id' => $kit->id,
                        'user_id' => $driver->id,
                        'error' => $e->getMessage(),
                    ]);
                    $this->warn(sprintf(
                        'Skipped kit %d (user %d): %s',
                        $kit->id,
                        $driver->id,
                        $e->getMessage(),
                    ));
                    $skipped++;
                }
            }
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::info('scout:record-check-ins: completed', [
            'recorded' => $recorded,
            'kits_processed' => $kitsProcessed,
            'orgs_processed' => $orgsProcessed,
            'skipped' => $skipped,
            'duration_ms' => $durationMs,
            'lookback_minutes' => $lookbackMinutes,
        ]);

        $overrunMs = (int) config('scout.check_ins.overrun_ms');

        if ($overrunMs > 0 && $durationMs >= $overrunMs) {
            $message = 'scout:record-check-ins: cycle exceeded its duration budget';

            Log::warning($message, [
                'duration_ms' => $durationMs,
                'threshold_ms' => $overrunMs,
                'over_threshold_multiple' => round($durationMs / $overrunMs, 1),
                'kits_processed' => $kitsProcessed,
                'orgs_processed' => $orgsProcessed,
            ]);

            // The log alone reaches no alerting channel here; report() is what
            // actually surfaces a recurrence.
            report(new RuntimeException($message));
        }

        $this->info(sprintf(
            'Recorded %d check-in(s) across %d kit(s); skipped %d.',
            $recorded,
            $kitsProcessed,
            $skipped,
        ));

        return self::SUCCESS;
    }

    /**
     * N-slot Cache::add semaphore. The lease is a crash-recovery TTL (must
     * stay ≤ lookback_minutes, DEV-2348), refreshed by heartbeatConcurrencySlot
     * while this process runs so the ceiling holds independent of cycle
     * duration (AC4). Returns false when every slot is held, or when the
     * knobs are non-positive (misconfig is a warning, not a silent busy).
     */
    private function acquireConcurrencySlot(): bool
    {
        $slots = (int) config('scout.check_ins.max_concurrent_slots');
        $leaseSeconds = (int) config('scout.check_ins.slot_lease_minutes') * 60;

        if ($slots < 1 || $leaseSeconds < 1) {
            Log::warning('scout:record-check-ins: concurrency slots misconfigured — skipping cycle', [
                'max_concurrent_slots' => $slots,
                'slot_lease_minutes' => (int) config('scout.check_ins.slot_lease_minutes'),
            ]);
            report(new RuntimeException('scout:record-check-ins: max_concurrent_slots/slot_lease_minutes is not positive'));
            $this->warn('Concurrency slots misconfigured — skipping cycle.');

            return false;
        }

        $owner = (string) Str::uuid();

        for ($i = 0; $i < $slots; $i++) {
            $key = self::SLOT_KEY_PREFIX.$i;

            if (Cache::add($key, $owner, $leaseSeconds)) {
                $this->slotKey = $key;
                $this->slotOwner = $owner;

                return true;
            }
        }

        Log::info('scout:record-check-ins: no concurrency slot available — skipping cycle');
        $this->info('No concurrency slot available — skipping cycle.');

        return false;
    }

    private function heartbeatConcurrencySlot(): void
    {
        if ($this->slotKey === null || $this->slotOwner === null) {
            return;
        }

        $leaseSeconds = (int) config('scout.check_ins.slot_lease_minutes') * 60;

        if (Cache::get($this->slotKey) !== $this->slotOwner) {
            return;
        }

        Cache::put($this->slotKey, $this->slotOwner, $leaseSeconds);
    }

    private function releaseConcurrencySlot(): void
    {
        if ($this->slotKey === null || $this->slotOwner === null) {
            return;
        }

        if (Cache::get($this->slotKey) === $this->slotOwner) {
            Cache::forget($this->slotKey);
        }

        $this->slotKey = null;
        $this->slotOwner = null;
    }

    /**
     * Enumerate drivers the same way Scout portal access is granted (see
     * {@see ClarityScoutMiddleware}): a kit assignment, the `clarity-scout`
     * permission (team-scoped by org), and the CLARITY_SCOUT Pennant feature
     * active for the user.
     *
     * @return Collection<int, User>
     */
    private function scoutEnabledDrivers(): Collection
    {
        return User::query()
            ->whereHas('kitSettings')
            ->with('kitSettings.kit')
            ->get()
            ->filter(function (User $driver): bool {
                if (! $driver->kit_id || ! $driver->org_id) {
                    return false;
                }

                setPermissionsTeamId($driver->org_id);

                return $driver->hasPermissionTo('clarity-scout')
                    && Feature::for($driver)->active(FeatureEnum::CLARITY_SCOUT->slug());
            })
            ->values();
    }

    /**
     * @param  list<int>  $stagingOrgIds
     */
    private function recordCheckInsForKit(Kit $kit, int $orgId, array $stagingOrgIds, Carbon $start, Carbon $end): int
    {
        $matches = ScoutCheckInMatchQuery::handle([
            'kit_mapping' => $kit->kit_mapping,
            'org_id' => $orgId,
            'staging_org_ids' => $stagingOrgIds,
            'start' => $start->utc()->toDateTimeString(),
            'end' => $end->utc()->toDateTimeString(),
        ]);

        if ($matches === []) {
            return 0;
        }

        $now = now();
        $rows = [];

        foreach ($matches as $match) {
            $rows[] = [
                'kit_id' => $kit->id,
                'org_id' => $orgId,
                'hotlist_id' => $match['hotlist_id'],
                'address_id' => $match['address_id'],
                'is_staged' => $match['is_staged'],
                'matched_at' => $match['matched_at'],
                'hour_bucket' => $match['hour_bucket'],
                'lat' => $match['lat'],
                'lng' => $match['lng'],
                'addr_lat' => $match['addr_lat'],
                'addr_lng' => $match['addr_lng'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return (int) ScoutCheckIn::query()->insertOrIgnore($rows);
    }
}
