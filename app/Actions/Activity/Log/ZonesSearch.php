<?php

declare(strict_types=1);

namespace App\Actions\Activity\Log;

use Illuminate\Support\Arr;

class ZonesSearch extends DefaultLog
{
    public static string $log_name = 'Zones Search';

    public function description(string $logName): string
    {
        $humanized = [];

        if (Arr::has($this->data, 'name')) {
            $humanized[] = 'Name';
        }

        if (Arr::has($this->data, 'assignedDriver')) {
            $humanized[] = 'Assigned driver';
        }

        if (array_key_exists('isNoScanZone', $this->data)) {
            $humanized[] = $this->data['isNoScanZone'] ? 'No-scan' : 'Patrol';
        }

        if (Arr::has($this->data, 'color')) {
            $humanized[] = 'Color';
        }

        if ($humanized === []) {
            return ':causer.email searched for zones';
        }

        return ':causer.email searched for zones by '.\implode(', ', $humanized);
    }
}
