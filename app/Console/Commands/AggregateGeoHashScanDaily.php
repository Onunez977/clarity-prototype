<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @codeCoverageIgnore
 */
class AggregateGeoHashScanDaily extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:aggregate-geohash-scan-daily {days=2}';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $days = (int) $this->argument('days');

        DB::connection('platform')->unprepared(
            "
INSERT INTO aggregate_scan_count_geohash_d (geohash, kit_id, utc_date, count)
  with sscan as (select kit_id,
                      st_geohash(scans.geo, 6) geohash,
                      CASE WHEN timezones.tzid IS NULL
                          THEN scans.scanned_at
                          ELSE
                          ((scans.scanned_at AT TIME ZONE timezones.tzid::text) AT TIME ZONE 'UTC'::text)::date
                          END AS utc_date
    FROM scans
        left join lateral (select tzid from timezones where st_within(scans.geo, timezones.geom) limit 1) timezones on true
    where st_x(scans.geo) != 0 and scans.created_at
          between '".now()->subDays($days)->startOfDay()."'::timestamp without time zone
          and '".now()->subDays($days)->endOfDay()."'::timestamp without time zone and is_visible = true)
select geohash,kit_id,utc_date,count(*) from sscan GROUP BY sscan.geohash,
             sscan.kit_id,
             utc_date

ON CONFLICT (geohash, kit_id, utc_date) do nothing;
"
        );

        Log::info('AggregateGeoHashScanDailyJob: '.$days.' days');
    }
}
