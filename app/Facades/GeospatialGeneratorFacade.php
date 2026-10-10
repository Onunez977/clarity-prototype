<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @codeCoverageIgnore
 */
class GeospatialGeneratorFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'geospatial-generator';
    }
}
