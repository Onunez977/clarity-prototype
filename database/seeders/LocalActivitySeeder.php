<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Activitylog\Models\Activity;

class LocalActivitySeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()
            ->where('email', 'test@tester.com')
            ->first();

        if (! $user) {
            dump('Please run "php artisan db:seed --class=LocalDataSeeder" first.');

            return;
        }

        // Scans
        for ($i = 0; $i < 5; $i++) {
            Activity::create([
                'causer_id' => $user->id,
                'causer_type' => 'user',
                'log_name' => 'Scans Search',
                'event' => 'Scans Search',
                'description' => $user->email.' searched for scans by Plate',
                'properties' => [
                    'plateNumber' => ['12345'],
                    '_request' => [
                        'clientIp' => '127.0.0.1',
                        'userAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:135.0) Gecko/20100101 Firefox/135.0',

                    ],
                ],
            ]);
        }
    }
}
