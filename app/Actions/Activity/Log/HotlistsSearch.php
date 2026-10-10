<?php

namespace App\Actions\Activity\Log;

use App\Actions\Activity\Casts\ArrayOfUpperCase;
use Illuminate\Support\Arr;

class HotlistsSearch extends DefaultLog
{
    public static string $log_name = 'Hotlist Search';

    protected array $casts = [
        'plateNumber' => ArrayOfUpperCase::class,
        'vin' => ArrayOfUpperCase::class,
    ];

    public function description(string $logName): string
    {
        $humanized = [];

        if (Arr::has($this->data, 'plateNumber')) {
            $humanized[] = 'Plate';
        }

        if (Arr::has($this->data, 'vin')) {
            $humanized[] = 'VIN';
        }

        if ($humanized === []) {
            return ':causer.email searched for hotlists';
        }

        return ':causer.email searched for hotlists by '.\implode(', ', $humanized);
    }
}
