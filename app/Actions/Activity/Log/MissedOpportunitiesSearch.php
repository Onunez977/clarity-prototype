<?php

namespace App\Actions\Activity\Log;

use App\Actions\Activity\Casts\ArrayOfUpperCase;
use Illuminate\Support\Arr;

class MissedOpportunitiesSearch extends DefaultLog
{
    public static string $log_name = 'Missed Opportunity Search';

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
        ];

        foreach ($fields as $field_key => $field_description) {
            if (Arr::has($this->data, $field_key)) {
                $humanized[] = $field_description;
            }
        }

        $human = \implode(', ', $humanized);
        if ($humanized) {
            $human = 'searched for missed opportunities by '.$human;
        }

        return ':causer.email '.$human;
    }
}
