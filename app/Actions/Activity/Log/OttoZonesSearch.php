<?php

declare(strict_types=1);

namespace App\Actions\Activity\Log;

class OttoZonesSearch extends ZonesSearch
{
    use MarksOttoSearch;

    public static string $log_name = 'Otto Zones Search';
}
