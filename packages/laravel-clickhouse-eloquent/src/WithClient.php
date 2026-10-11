<?php

namespace LaravelClickhouseEloquent;

use Illuminate\Support\Facades\DB;

trait WithClient
{
    public function getThisClient(): ClickhouseHttpClient
    {
        return DB::connection($this->connection)->getClient();
    }

    /**
     * @return ClickhouseHttpClient
     * @deprecated use $this->getThisClient() instead
     */
    public static function getClient(): ClickhouseHttpClient
    {
        return DB::connection((new static())->connection)->getClient();
    }
}