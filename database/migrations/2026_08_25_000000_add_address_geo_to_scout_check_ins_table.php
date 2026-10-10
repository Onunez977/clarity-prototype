<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The elected address's own coordinate, so uniqueness matches the grain
     * ScoutCheckInMatchQuery already elects: one row per (kit, org, place,
     * hour, side). `lat`/`lng` stay the earliest GPS fix's coordinates.
     *
     * Dropping the old unique index is required, not tidying: it has no
     * is_staged, so a corrected row for an address whose side changed collides
     * on (kit_id, address_id, hour_bucket) and insertOrIgnore discards it —
     * which is what strands a stale is_staged (DEV-2426).
     *
     * `org_id` is in the key because the recorder groups drivers by the DRIVER's
     * org and stamps that org id, not the kit's. Note this is NOT two orgs in one
     * cycle: kit_settings has kit_id as its primary key, so a kit has exactly one
     * assigned driver and therefore one org per cycle. The reachable path is
     * SEQUENTIAL — the kit is reassigned to another org's driver inside the same
     * hour, so a later cycle stamps a different org_id for the same (kit, geo,
     * hour). The old key separated those two rows only incidentally, via each
     * org's own address_id; without org_id here the second org's check-in is
     * silently dropped by insertOrIgnore and its address reads as never visited.
     *
     * New columns are nullable and unbackfilled. Postgres treats NULLs as
     * distinct in a unique index, so pre-change rows are exempt; backfilling
     * would instead fail the index build on the duplicates the bug created.
     */
    public function up(): void
    {
        $schema = Schema::connection('platform');

        // Guarded like the index below: if a previous up() added the columns and
        // then failed on the index build, Laravel wrote no migrations row, so the
        // next migrate re-runs this file and an unguarded ->double() would error
        // with "column already exists" and wedge it. Production hand-applies this
        // DDL as separate non-transactional statements, so that state is reachable.
        $schema->table('scout_check_ins', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('scout_check_ins', 'addr_lng')) {
                $table->double('addr_lng')->nullable()->after('lng');
            }

            if (! $schema->hasColumn('scout_check_ins', 'addr_lat')) {
                $table->double('addr_lat')->nullable()->after('addr_lng');
            }
        });

        $schema->table('scout_check_ins', function (Blueprint $table) use ($schema) {
            // Guarded so up() still runs after a down() that did not restore it.
            // hasIndex()/hasColumn() query immediately; the $table commands flush at
            // the end of the closure. Keep every guard BEFORE the command it guards.
            if ($schema->hasIndex('scout_check_ins', 'scout_check_ins_kit_id_address_id_hour_bucket_unique')) {
                $table->dropUnique('scout_check_ins_kit_id_address_id_hour_bucket_unique');
            }

            // Named explicitly because Laravel's implicit name is over
            // PostgreSQL's 63-character identifier limit. Guarded like the two
            // statements above: a hand-apply that added the constraint without
            // writing the migrations row would otherwise error here on the re-run.
            if (! $schema->hasIndex('scout_check_ins', 'scout_check_ins_geo_hour_side_unique')) {
                $table->unique(
                    ['kit_id', 'org_id', 'addr_lng', 'addr_lat', 'hour_bucket', 'is_staged'],
                    'scout_check_ins_geo_hour_side_unique',
                );
            }
        });
    }

    /**
     * Deliberately not a strict inverse (DEV-2426, Dalton 2026-08-25): it does
     * NOT restore the old unique index, because the new grain legitimately
     * permits two rows sharing (kit_id, address_id, hour_bucket) — one per
     * side — so the restore would fail on valid data. The trade-off is that a
     * rollback leaves the table with no uniqueness guard, and pre-change code
     * rolled back with it would accumulate true duplicates through
     * insertOrIgnore.
     */
    public function down(): void
    {
        $schema = Schema::connection('platform');

        $schema->table('scout_check_ins', function (Blueprint $table) use ($schema) {
            // Guarded both ways so a partially-applied up() can still be undone:
            // without this, an interrupted index build leaves columns that down()
            // cannot drop and up() cannot re-add.
            if ($schema->hasIndex('scout_check_ins', 'scout_check_ins_geo_hour_side_unique')) {
                $table->dropUnique('scout_check_ins_geo_hour_side_unique');
            }

            $existing = array_values(array_filter(
                ['addr_lat', 'addr_lng'],
                fn (string $column): bool => $schema->hasColumn('scout_check_ins', $column),
            ));

            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
