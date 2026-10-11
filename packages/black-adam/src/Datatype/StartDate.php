<?php

namespace InsightLPR\BlackAdam\Datatype;

use Illuminate\Support\Carbon;

class StartDate extends Carbon
{
    public function toCarbon(): Carbon
    {
        return Carbon::instance($this->toDateTime());
    }
}
