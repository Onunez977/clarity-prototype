<?php

declare(strict_types=1);

namespace App\Actions\Activity\Log;

class OttoHitsSearch extends HitsSearch
{
    use MarksOttoSearch;

    public static string $log_name = 'Otto Hits Search';
}
