<?php

declare(strict_types=1);

namespace App\Actions\Activity\Log;

class OttoHotlistsSearch extends HotlistsSearch
{
    use MarksOttoSearch;

    public static string $log_name = 'Otto Hotlist Search';
}
