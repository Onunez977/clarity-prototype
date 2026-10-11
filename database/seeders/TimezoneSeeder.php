<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Timezone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Rap2hpoutre\FastExcel\FastExcel;

class TimezoneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (Timezone::first()) {
            return;
        }

        if (! Storage::disk('local')->exists('timezone.csv')) {
            echo with(app()->environment('local'), fn ($value) => $value ? 'Downloading TZ file into storage/app/timezone.csv'.PHP_EOL : '');

            Storage::disk('local')->put('timezone.csv', Http::get('https://s3.us-east-2.amazonaws.com/assets.insightlpr.io/develop/timezone.csv')->body());
        }

        (new FastExcel)
            ->import(Storage::disk('local')->path('timezone.csv'))
            ->chunk(10)
            ->each(function ($row_chunks) {
                $upsert_data = $row_chunks->map(fn ($row) => [
                    'gid' => $row['gid'],
                    'tzid' => $row['tzid'],
                    'geom' => $row['geom'],
                ])->toArray();

                DB::connection('zod')->table('timezones')->upsert($upsert_data, ['gid']);
            });
    }
}
