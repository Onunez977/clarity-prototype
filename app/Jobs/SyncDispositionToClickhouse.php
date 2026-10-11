<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

class SyncDispositionToClickhouse implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public int $scanId,
        public string $disposition,
    ) {
        $this->onQueue('{low}');
    }

    public function handle(): void
    {
        $escapedDisposition = addslashes($this->disposition);
        $scanId = $this->scanId;

        $tables = [
            'scans_cold' => 'id',
            'scans_hot' => 'id',
            'hits_cold' => 'scan_id',
            'hits_hot' => 'scan_id',
            'missed_opportunities' => 'scan_id',
        ];

        // Let failures propagate so the queue retries instead of silently logging
        // and dropping the update — the ALTER…UPDATE is idempotent, so retries are safe.
        foreach ($tables as $table => $column) {
            DB::connection('clarity_clickhouse_write')
                ->statement("ALTER TABLE clarity.{$table} UPDATE disposition = '{$escapedDisposition}' WHERE {$column} = {$scanId}");
        }
    }
}
