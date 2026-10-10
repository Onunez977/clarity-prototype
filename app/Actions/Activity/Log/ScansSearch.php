<?php

declare(strict_types=1);

namespace App\Actions\Activity\Log;

use App\Actions\Activity\Casts\ArrayOfUpperCase;
use Illuminate\Support\Arr;

class ScansSearch extends DefaultLog
{
    public static string $log_name = 'Scans Search';

    protected array $casts = [
        'plateNumber' => ArrayOfUpperCase::class,
        'vin' => ArrayOfUpperCase::class,
    ];

    protected array $fillable = [
        'plateNumber',
    ];

    public function description(string $logName): string
    {
        $humanized = [];
        if (Arr::has($this->data, 'plateNumber')) {
            $humanized[] = 'Plate';
        }

        if ($humanized === []) {
            return ':causer.email searched for scans';
        }

        return ':causer.email searched for scans by '.\implode(', ', $humanized);
    }
}
