<?php

namespace App\Actions\Activity\Log;

use App\Actions\Activity\Casts\ArrayOfUpperCase;
use Illuminate\Support\Arr;

class HitsSearch extends DefaultLog
{
    public static string $log_name = 'Hits Search';

    protected array $casts = [
        'plateNumber' => ArrayOfUpperCase::class,
        'vin' => ArrayOfUpperCase::class,
    ];

    public function description(string $logName): string
    {
        $humanized = [];
        $fields = [
            'plateNumber' => 'Plate',
            'vin' => 'VIN',
            'vl6' => 'VL6',
        ];

        foreach ($fields as $field_key => $field_description) {
            if (Arr::has($this->data, $field_key)) {
                $humanized[] = $field_description;
            }
        }

        if ($humanized === []) {
            return ':causer.email searched for hits';
        }

        return ':causer.email searched for hits by '.\implode(', ', $humanized);
    }
}
