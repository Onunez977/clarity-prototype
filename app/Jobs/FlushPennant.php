<?php

namespace App\Jobs;

use App\Models\Org;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Pennant\Feature;

class FlushPennant implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public function __construct()
    {
        $this->onQueue('{high}');
    }

    public function handle(): void
    {
        Feature::flushCache();
        Feature::purge();

        Org::active()
            ->cursor()
            ->each(function ($org) {
                cache()->forget('org_pro_enabled_'.$org->id);
                cache()->forget('org_pro_features_'.$org->id);
            });
    }
}
