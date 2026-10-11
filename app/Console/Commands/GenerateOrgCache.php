<?php

namespace App\Console\Commands;

use App\Jobs\Org\CacheOrgHotlistClients;
use App\Jobs\Org\CacheOrgHotlistLienholders;
use App\Jobs\Org\CacheOrgRdnClients;
use App\Models\Org;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class GenerateOrgCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:org-cache {org?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pre-calculate and cache helpers for orgs.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        if ($this->argument('org')) {
            $this->queueBatch(Org::find($this->argument('org')));
        } else {
            Org::active()->cursor()->each(function ($org) {
                $this->queueBatch($org);
            });
        }
    }

    private function queueBatch(Org $org): void
    {
        Bus::batch([
            new CacheOrgHotlistClients(org: $org),
            new CacheOrgHotlistLienholders(org: $org),
            // new CacheOrgRdnClients(org: $org),
        ])
            ->allowFailures()
            ->onQueue('{low}')
            ->name('Org Cache: '.$org->id)
            ->dispatch();
    }
}
