<?php

namespace App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @codeCoverageIgnore
 */
class RefreshRecoveryViews implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public $timeout = 600;

    public $backoff = 30;

    public $tries = 3;

    public function __construct()
    {
        $this->onQueue('{low}');
    }

    public function handle(): void
    {
        DB::connection('pg')->unprepared('select platform.upsert_recoveries_for_days(5)');
        Log::info('called platform.upsert_recoveries_for_days(5)');

        DB::connection('pg')->unprepared('call p_aggregate_kit_user_scan_report()');
        Log::info('called p_aggregate_kit_user_scan_report');
    }
}
