<?php

namespace App\Console\Commands;

use App\Jobs\GeocodeRecoveryAddress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @codeCoverageIgnore
 */
class GeocodeRecoveryAddresses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:geocode-recovery-addresses';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        Log::shareContext(['source' => 'GeocodeRecoveryAddresses']);
        Log::info('Starting geocoding of recovery addresses.');

        $data = DB::connection('pg')->unprepared("
            select * from platform.recoveries
            where address_id is not null
            and geo is null
            and repo_date > now() - interval '1 day' * 4
        ");

        if ($data === true) {
            // Nothing found.
            $data = [];
        }

        $batches = collect($data)
            ->map(fn ($row) => new GeocodeRecoveryAddress(addressId: $row->id))
            ->toArray();

        Bus::batch($batches)
            ->onQueue('{low}')
            ->allowFailures()
            ->finally(function () {
                Log::info('Finished geocoding of recovery addresses.');
            })
            ->name('Geocode Recovery Addresses')
            ->dispatch();
    }
}
