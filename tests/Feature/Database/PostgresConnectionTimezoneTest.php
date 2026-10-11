<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins the Postgres session timezone the test suite runs under.
 *
 * Eloquent writes datetimes as naive `Y-m-d H:i:s` literals with no offset, and Postgres
 * resolves a naive literal against the SESSION timezone before storing it in a `timestamptz`
 * column. No connection in `config/database.php` sets a `timezone`, so the session inherits
 * the server's `postgresql.conf` default — UTC in CI, but whatever the host happens to be
 * locally. `phpunit.xml` sets `PGTZ=UTC`, which libpq applies to every connection the test
 * process opens; this asserts it took effect.
 *
 * DEV-2528: on a host defaulting to `America/Chicago` this stored
 * `platform.kit_shifts.started_at`/`ended_at` exactly 18,000s (5h) off. It surfaced as
 * unexplained Scout shift-close failures and was mis-diagnosed across four prior tickets,
 * so this asserts the cause directly rather than leaving it to be inferred from shift
 * arithmetic three hops downstream.
 */
class PostgresConnectionTimezoneTest extends TestCase
{
    public function test_a_naive_datetime_round_trips_through_a_timestamptz_column_unshifted(): void
    {
        $connection = DB::connection('platform');
        $connection->statement('CREATE TEMP TABLE IF NOT EXISTS dev2528_tz_probe (tz_col timestamptz)');
        $connection->table('dev2528_tz_probe')->delete();

        $written = Carbon::parse('2026-09-14 12:00:00', 'UTC');

        // Exactly what Eloquent sends for a datetime-cast attribute: no offset.
        $connection->table('dev2528_tz_probe')->insert([
            'tz_col' => $written->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame(
            0,
            Carbon::parse($connection->table('dev2528_tz_probe')->value('tz_col'))->getTimestamp() - $written->getTimestamp(),
            'A naive datetime written to a timestamptz column came back as a different instant. '
            .'The Postgres session timezone is not UTC — check PGTZ in phpunit.xml.'
        );
    }
}
