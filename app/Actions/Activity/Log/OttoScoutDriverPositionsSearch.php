<?php

declare(strict_types=1);

namespace App\Actions\Activity\Log;

class OttoScoutDriverPositionsSearch extends ScoutDriverPositionsSearch
{
    use MarksOttoSearch;

    public static string $log_name = 'Otto Scout Driver Position Search';
}
