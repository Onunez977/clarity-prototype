<?php

declare(strict_types=1);

namespace App\Actions\Activity\Log;

use Illuminate\Support\Arr;

class ScoutDriverPositionsSearch extends DefaultLog
{
    public static string $log_name = 'Scout Driver Position Search';

    public function description(string $logName): string
    {
        $humanized = [];

        if (Arr::has($this->data, 'driverName')) {
            $humanized[] = 'Driver';
        }

        if (Arr::has($this->data, 'kitName') || Arr::has($this->data, 'kitId')) {
            $humanized[] = 'Kit';
        }

        if ($humanized === []) {
            return ':causer.email searched for Scout driver positions';
        }

        return ':causer.email searched for Scout driver positions by '.\implode(', ', $humanized);
    }
}
