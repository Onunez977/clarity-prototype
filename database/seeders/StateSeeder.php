<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Geocoding\TigerStateColumns;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Rap2hpoutre\FastExcel\FastExcel;

class StateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (DB::table('tiger.state')->count()) {
            return;
        }

        foreach (TigerStateColumns::ensure() as $column) {
            $this->command->info("Added column {$column} to tiger.state");
        }

        if (! Storage::disk('local')->exists('state.csv')) {
            echo with(app()->environment('local'), fn ($value) => $value ? 'Downloading state file into storage/app/state.csv'.PHP_EOL : '');
            Storage::disk('local')->put('state.csv', Http::get('https://s3.us-east-2.amazonaws.com/assets.insightlpr.io/develop/state.csv')->body());
        }

        (new FastExcel)
            ->import(Storage::disk('local')->path('state.csv'))
            ->chunk(10)
            ->each(function ($row_chunks) {
                try {
                    DB::table('tiger.state')->insert($row_chunks->toArray());
                } catch (\Throwable $throwable) {
                    $this->command->error('Failure to insert state:'.get_class($throwable).' | '.\Str::substr($throwable->getMessage(), 0, 400));
                }
            });
    }
}
