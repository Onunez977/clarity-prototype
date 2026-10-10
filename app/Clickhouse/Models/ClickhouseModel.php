<?php

namespace App\Clickhouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LaravelClickhouseEloquent\Builder as ClickhouseBuilder;
use LaravelClickhouseEloquent\Connection as ClickhouseConnection;

abstract class ClickhouseModel extends Model
{
    protected $connection = 'clarity_clickhouse';

    public $timestamps = false;

    public $incrementing = false;

    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();

        if ($connection instanceof ClickhouseConnection) {
            return new ClickhouseBuilder($connection);
        }

        return parent::newBaseQueryBuilder();
    }

    public function scopeFinal($query)
    {
        $baseQuery = $query->getQuery();

        if (method_exists($baseQuery, 'final')) {
            $baseQuery->final();
        }

        return $query;
    }

    /**
     * Resolve the hot or cold table for a query window: recent windows (start
     * within 90 days) use the model's *_hot table when it exposes one; older
     * windows fall to the default (cold) table. Centralizes the 90-day threshold
     * so every Scout surface agrees instead of hand-rolling the ternary.
     */
    public static function forWindow($start): static
    {
        $recent = Carbon::parse($start)->diffInDays(now()) <= 90;

        return $recent && method_exists(static::class, 'hot') ? static::hot() : new static;
    }
}
