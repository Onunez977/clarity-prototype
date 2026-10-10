<?php

namespace InsightLPR\BlackAdam\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \InsightLPR\BlackAdam\BlackAdam
 */
class BlackAdam extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \InsightLPR\BlackAdam\BlackAdam::class;
    }
}
