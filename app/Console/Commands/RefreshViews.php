<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

use function Laravel\Prompts\progress;

/**
 * @codeCoverageIgnore
 */
class RefreshViews extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:refresh-views';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $views = collect([
            'recovery_address',
            'recoveries',
            'staging_orgs_hotlist_count',
        ]);

        $progress = progress(label: 'Refreshing Views...', steps: $views->count());
        $progress->start();

        $views->each(function ($view) use ($progress) {
            try {
                Schema::connection('zod')->refreshMaterializedView($view, concurrently: false);
            } catch (\Exception $exception) {
                //
            }

            $progress->advance();
        });

        $progress->finish();
    }
}
