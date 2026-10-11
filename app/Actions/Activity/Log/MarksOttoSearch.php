<?php

declare(strict_types=1);

namespace App\Actions\Activity\Log;

/**
 * Same search wording as the Filament tracker, with Otto named in the sentence
 * so the activity row is not mistaken for a page filter search.
 */
trait MarksOttoSearch
{
    public function description(string $logName): string
    {
        return str_replace(
            ' searched for ',
            ' searched via Otto for ',
            parent::description($logName),
        );
    }
}
