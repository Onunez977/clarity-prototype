<?php

declare(strict_types=1);

namespace App\Actions\Activity\Log;

class OttoScansSearch extends ScansSearch
{
    use MarksOttoSearch;

    public static string $log_name = 'Otto Scans Search';
}
