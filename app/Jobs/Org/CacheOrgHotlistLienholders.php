<?php

namespace App\Jobs\Org;

use App\Models\Org;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CacheOrgHotlistLienholders implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public function __construct(
        public Org $org
    ) {
        $this->onQueue('{low}');
    }

    public function handle(): void
    {
        $this->org->getHotlistLienholders();
    }
}
