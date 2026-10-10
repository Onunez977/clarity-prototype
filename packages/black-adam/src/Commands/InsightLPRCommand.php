<?php

namespace InsightLPR\BlackAdam\Commands;

use Illuminate\Console\Command;

class InsightLPRCommand extends Command
{
    public $signature = 'insightlpr';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
