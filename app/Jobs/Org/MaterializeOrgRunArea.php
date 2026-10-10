<?php

declare(strict_types=1);

namespace App\Jobs\Org;

use App\Models\Org;
use App\Services\RunArea\Materializer;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MaterializeOrgRunArea implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public function __construct(
        public Org $org,
    ) {
        $this->onQueue('{low}');
    }

    public function handle(): void
    {
        $runArea = $this->org->runArea;

        if ($runArea === null || empty($runArea->inputs)) {
            return;
        }

        Materializer::materialize($runArea);
    }
}
