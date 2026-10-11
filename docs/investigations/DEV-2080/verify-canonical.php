<?php

/**
 * DEV-2080 — Scout canonical-vs-implementation verification harness.
 *
 * Read-only. Runs each Scout data surface against Eric Balsa's canonical
 * order-counting rules and prints a PASS / DIVERGES table with the live delta,
 * tagged by the follow-up fix ticket each check belongs to. After a fix lands,
 * re-run for the affected org: the row should flip from DIVERGES to PASS.
 *
 * Canonical baseline = the ClickHouse CDC copy of zod (zod.public_* / clarity.*),
 * NOT raw prod Postgres (local zod is dev). clarity.hotlist FINAL was confirmed
 * dup-free and equal to zod on open/repossessed counts (only status=5/closed
 * differs, which Scout never counts). A prod Postgres spot-check is still worth
 * doing before fixes ship.
 *
 * RUN (from the platform repo root):
 *   php artisan tinker docs/investigations/DEV-2080/verify-canonical.php
 * or inside `php artisan tinker`:
 *   require 'docs/investigations/DEV-2080/verify-canonical.php';
 *
 * Verify one org after a fix:
 *   require ...; scout_audit(1271);            // auto-resolves the top-scan kit
 *   require ...; scout_audit(1271, 14904);     // explicit kit
 *
 * Representative orgs (each exercises a different divergence class):
 *   1271   — direct opens / addresses / repos / map-hits status leak / scans+hits FINAL
 *   518    — forwarder/agent staging (staged tile reads 0 vs canonical)
 *   160    — own/client MV double-count (also 717)
 *   418753 — non-null/rejected dispositions (disposition-consistency, finding G)
 *
 * ILLUSTRATIVE BASELINE (2026-06-22, pre-fix). Live CDC data drifts a few % between
 * runs — the delta SIGN and rough magnitude are the signal, not the exact count. Re-run
 * for current numbers. The PASS rows below are the post-fix target for each DIVERGES row.
 *   1271 hotlistStats open:  scout ~9,000 vs canonical ~8,150 (Δ ~+850, ticket 1)
 *   1271 addresses direct:   scout ~210k  vs canonical(distinct-geo) ~29k (ticket 3)
 *   1271 scans FINAL (kit 14904): ~2.97M raw vs ~2.63M FINAL (+13%, ticket 4)
 *   1271 trips Live status: ~3,650 all-status vs ~350 open (ticket 5 — most trip hits non-open)
 *   1271 map hits leak: ~5,900 open vs ~4,000 non-open leaked (ticket 5)
 *   518  staged: scout 0 vs canonical ~593k (ticket 2 — staged tile broken for agent orgs)
 *   160  own/client double-count: own ~288k + client ~286k summed (ticket 3/H)
 *   418753 disposition: stats ~1,520 vs recent/heatmap ~1,281 (Δ ~239 rejected, ticket 5/G)
 */

use App\Models\Org;
use Illuminate\Support\Facades\DB;

if (! function_exists('scout_audit')) {
    /** Single scalar count helper. */
    function _ch_count(string $sql): int
    {
        return (int) (DB::connection('clarity_clickhouse')->selectOne($sql)['c'] ?? 0);
    }

    /**
     * Run the full canonical-vs-Scout comparison suite for one org and echo a table.
     *
     * @param  int  $orgId  the logged-in org (Scout's $user->org_id)
     * @param  int|null  $kitId  kit for scan/stat checks; auto-resolved if null
     */
    function scout_audit(int $orgId, ?int $kitId = null): array
    {
        $ZERO = '0101000020E610000000000000000000000000000000000000'; // 0,0 EWKB
        $OPEN = 1;
        $REPO = 3;

        // Resolve the kit with the most recent hot scans if not supplied.
        $kitId ??= (int) (DB::connection('clarity_clickhouse')->selectOne(
            "SELECT kit_id AS c FROM clarity.scans_hot WHERE org_id=$orgId GROUP BY kit_id ORDER BY count() DESC LIMIT 1"
        )['c'] ?? 0);

        // Forwarder orgs. The app uses Org::stagingOrgIds() (zod Postgres connection,
        // which is a dev DB locally → empty for prod orgs). For a LIVE ClickHouse audit
        // we resolve from the same CDC data being audited (zod.public_approval_list),
        // which mirrors stagingOrgIds()'s logic: agent_org_id = me → staging_org_id.
        $appStaging = Org::find($orgId)?->stagingOrgIds()->toArray() ?? [];
        $stagingOrgIds = $appStaging ?: array_map(
            fn ($r) => (int) $r['staging_org_id'],
            DB::connection('clarity_clickhouse')->select(
                "SELECT DISTINCT staging_org_id FROM zod.public_approval_list WHERE _peerdb_is_deleted=0 AND agent_org_id=$orgId"
            )
        );
        $sList = $stagingOrgIds ? implode(',', array_map('intval', $stagingOrgIds)) : null;

        // address-id membership subqueries (mirrors HotlistAddressGeohashQuery raw path)
        $haOf = fn (string $hotlistSub) => "SELECT address_id FROM zod.public_hotlist_address WHERE hotlist_id IN ($hotlistSub)";

        $rows = [];
        $add = function (string $ticket, string $surface, ?int $scout, ?int $canon, string $expectAfter, string $note = '') use (&$rows) {
            if ($scout === null || $canon === null) {
                $status = 'N/A';
            } else {
                $status = $scout === $canon ? 'PASS' : 'DIVERGES (Δ '.sprintf('%+d', $scout - $canon).')';
            }
            $rows[] = compact('ticket', 'surface', 'scout', 'canon', 'expectAfter', 'status', 'note');
        };

        // ── Ticket 1 — hotlistStats() open: plate filter ────────────────────
        $add('1', 'hotlistStats() open',
            _ch_count("SELECT count() c FROM clarity.hotlist FINAL WHERE _peerdb_is_deleted=0 AND org_id=$orgId AND status=$OPEN"),
            _ch_count("SELECT count() c FROM clarity.hotlist FINAL WHERE _peerdb_is_deleted=0 AND org_id=$orgId AND status=$OPEN AND length(plate)>3"),
            'scout == canonical (apply hasPlate, >3 not >=3)');

        // ── Ticket 2 — hotlistStats() staged: forwarder scoping + status + plate ─
        $add('2', 'hotlistStats() staged',
            _ch_count("SELECT count() c FROM clarity.hotlist FINAL WHERE _peerdb_is_deleted=0 AND org_id=$orgId AND is_staged=1"),
            $sList ? _ch_count("SELECT count() c FROM clarity.hotlist FINAL WHERE _peerdb_is_deleted=0 AND org_id IN ($sList) AND is_staged=1 AND status=$OPEN AND length(plate)>3") : 0,
            'scout rewritten to forwarder orgs (stagingOrgIds) + status=1 + plate',
            $sList ? count($stagingOrgIds).' forwarder orgs' : 'org has no forwarder orgs');

        // ── Ticket 3 — direct order-address density: status/plate/0,0 + distinct-geo ─
        $hlScout = "SELECT id FROM zod.public_hotlist WHERE _peerdb_is_deleted=0 AND org_id=$orgId";
        $hlCanon = "SELECT id FROM zod.public_hotlist WHERE _peerdb_is_deleted=0 AND org_id=$orgId AND status=$OPEN AND length(plate)>3";
        $add('3', 'addresses direct (distinct addr, no filters)',
            _ch_count("SELECT count(DISTINCT a.id) c FROM zod.public_address a WHERE a._peerdb_is_deleted=0 AND a.geo!='' AND a.id IN (".$haOf($hlScout).')'),
            _ch_count("SELECT count(DISTINCT a.geo) c FROM zod.public_address a WHERE a._peerdb_is_deleted=0 AND a.geo!='' AND a.geo!='$ZERO' AND a.id IN (".$haOf($hlCanon).')'),
            'distinct geo, status=1, plate>3, exclude 0,0 EWKB');

        // ── Ticket 3 — staging order-address density: is_staged ──────────────
        if ($sList) {
            $hlSStaging = "SELECT id FROM zod.public_hotlist WHERE _peerdb_is_deleted=0 AND org_id IN ($sList) AND status=$OPEN";
            $hlSCanon = "SELECT id FROM zod.public_hotlist WHERE _peerdb_is_deleted=0 AND org_id IN ($sList) AND status=$OPEN AND is_staged=1 AND length(plate)>3";
            $add('3', 'addresses staging (no is_staged)',
                _ch_count("SELECT count(DISTINCT a.id) c FROM zod.public_address a WHERE a._peerdb_is_deleted=0 AND a.geo!='' AND a.id IN (".$haOf($hlSStaging).')'),
                _ch_count("SELECT count(DISTINCT a.geo) c FROM zod.public_address a WHERE a._peerdb_is_deleted=0 AND a.geo!='' AND a.geo!='$ZERO' AND a.id IN (".$haOf($hlSCanon).')'),
                'distinct geo, is_staged=1, status=1, plate>3, exclude 0,0');
        }

        // ── Ticket 3 / H — own/client MV double-count (INFO; MV stores counts, not addr ids) ─
        $own = _ch_count("SELECT toInt64(sum(address_count)) c FROM clarity.hotlist_address_geohash FINAL WHERE org_id=$orgId AND source='own'");
        $client = _ch_count("SELECT toInt64(sum(address_count)) c FROM clarity.hotlist_address_geohash FINAL WHERE org_id=$orgId AND source='client'");
        $rows[] = ['ticket' => '3/H', 'surface' => 'heatmapOrders own+client sum (MV)', 'scout' => $own + $client, 'canon' => $own,
            'expectAfter' => 'collapse source per geohash; decide if client belongs in direct',
            'status' => $client > 0 ? 'DIVERGES (client '.$client.' summed in)' : 'PASS (no client rows)', 'note' => "own=$own client=$client"];

        // ── Ticket 4 — stats() scans FINAL ───────────────────────────────────
        $add('4', "stats() scans FINAL (kit $kitId)",
            _ch_count("SELECT count() c FROM clarity.scans_hot WHERE org_id=$orgId AND kit_id=$kitId"),
            _ch_count("SELECT count() c FROM clarity.scans_hot FINAL WHERE org_id=$orgId AND kit_id=$kitId"),
            'scout == FINAL (add FINAL / ApplyFinal)');

        // ── Ticket 4 — trips hits_cold FINAL ──────────────────────────────────
        $add('4', 'trips hits_cold FINAL',
            _ch_count("SELECT count() c FROM clarity.hits_cold WHERE kit_org_id=$orgId AND hit_type='L'"),
            _ch_count("SELECT count() c FROM clarity.hits_cold FINAL WHERE kit_org_id=$orgId AND hit_type='L'"),
            'scout == FINAL (add FINAL)');

        // ── Ticket 5 — trips hotlist_status filter (no status → repossessed shown) ─
        $add('5', 'trips Live hits status filter',
            _ch_count("SELECT count() c FROM clarity.hits_cold FINAL WHERE kit_org_id=$orgId AND hit_type='L'"),
            _ch_count("SELECT count() c FROM clarity.hits_cold FINAL WHERE kit_org_id=$orgId AND hit_type='L' AND hotlist_status=$OPEN"),
            'trips add hotlist_status=Open filter');

        // ── Ticket 5 — map hits() status leak (!= Repossessed vs = Open) ──────
        $add('5', 'map hits() status (!= Repossessed)',
            _ch_count("SELECT count() c FROM clarity.hits_hot FINAL WHERE kit_org_id=$orgId AND hit_type IN ('L','S') AND hotlist_status!=$REPO"),
            _ch_count("SELECT count() c FROM clarity.hits_hot FINAL WHERE kit_org_id=$orgId AND hit_type IN ('L','S') AND hotlist_status=$OPEN"),
            'map hits filter = Open (or explicit allowed set)');

        // ── Ticket 5 / G — disposition consistency (stats counts all vs recent/heatmap NULL|Valid) ─
        $allDispo = _ch_count("SELECT toInt64(uniqExact((ocr, toStartOfHour(hit_created_at)))) c FROM clarity.hits_hot FINAL WHERE kit_org_id=$orgId AND hit_type='L'");
        $validDispo = _ch_count("SELECT toInt64(uniqExactIf((ocr, toStartOfHour(hit_created_at)), disposition IN ('New Hit','Valid') OR disposition IS NULL)) c FROM clarity.hits_hot FINAL WHERE kit_org_id=$orgId AND hit_type='L'");
        $add('5/G', 'disposition consistency (stats vs recent/heatmap)',
            $allDispo, $validDispo,
            'pick one rule: count-all OR NULL|Valid across all hit surfaces',
            $allDispo === $validDispo ? 'no rejected dispositions for this org' : ($allDispo - $validDispo).' rejected hits counted by stats only');

        // ── Reference baselines (INFO) ────────────────────────────────────────
        $rows[] = ['ticket' => 'ref', 'surface' => 'direct repos (status=3)', 'scout' => _ch_count("SELECT count() c FROM clarity.hotlist FINAL WHERE _peerdb_is_deleted=0 AND org_id=$orgId AND status=$REPO"),
            'canon' => null, 'expectAfter' => '—', 'status' => 'INFO', 'note' => 'canonical direct-repos count'];

        // ── print ─────────────────────────────────────────────────────────────
        echo "\n══ DEV-2080 canonical audit — org $orgId (kit $kitId) ══\n";
        printf("%-4s | %-42s | %12s | %12s | %s\n", 'TKT', 'SURFACE', 'SCOUT', 'CANONICAL', 'VERDICT');
        echo str_repeat('-', 110)."\n";
        foreach ($rows as $r) {
            printf("%-4s | %-42s | %12s | %12s | %s%s\n",
                $r['ticket'], $r['surface'],
                $r['scout'] === null ? '—' : number_format($r['scout']),
                $r['canon'] === null ? '—' : number_format($r['canon']),
                $r['status'], $r['note'] ? '  ['.$r['note'].']' : '');
        }
        echo "\n";

        return $rows;
    }
}

// Run the representative matrix when the file is executed directly.
foreach ([1271, 518, 160, 418753] as $org) {
    try {
        scout_audit($org);
    } catch (Throwable $e) {
        echo "\n!! org $org failed: ".$e->getMessage()."\n";
    }
}
