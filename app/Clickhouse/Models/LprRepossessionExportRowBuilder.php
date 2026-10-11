<?php

declare(strict_types=1);

namespace App\Clickhouse\Models;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use LaravelClickhouseEloquent\Connection as ClickhouseConnection;

/**
 * ExportCsv resolves a chunk with find() on the string triple key.
 * One IN list of ~10k keys exceeds ClickHouse max_query_size (262144, error 62),
 * so each lookup is split until the bound statement, including FORMAT JSON, fits.
 *
 * @extends Builder<LprRepossessionExportRow>
 */
class LprRepossessionExportRowBuilder extends Builder
{
    /**
     * Headroom under ClickHouse max_query_size (262144).
     */
    private const BOUND_QUERY_BUDGET = 200_000;

    /**
     * @param  Arrayable<int, mixed>|array<mixed>  $ids
     * @param  array<int, string>|string  $columns
     * @return Collection<int, LprRepossessionExportRow>
     */
    public function findMany($ids, $columns = ['*']): Collection
    {
        $ids = array_values($ids instanceof Arrayable ? $ids->toArray() : $ids);

        if ($ids === []) {
            return $this->model->newCollection();
        }

        if (! $this->getConnection() instanceof ClickhouseConnection) {
            return parent::findMany($ids, $columns);
        }

        $found = $this->model->newCollection();

        foreach ($this->batchesWithinBudget($ids) as $batch) {
            $found = $found->concat((clone $this)->whereKey($batch)->get($columns));
        }

        return $found;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, array<int, mixed>>
     */
    private function batchesWithinBudget(array $ids): array
    {
        if (count($ids) === 1 || $this->boundQueryBytes($ids) <= self::BOUND_QUERY_BUDGET) {
            return [$ids];
        }

        $mid = intdiv(count($ids), 2);

        return [
            ...$this->batchesWithinBudget(array_slice($ids, 0, $mid)),
            ...$this->batchesWithinBudget(array_slice($ids, $mid)),
        ];
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    private function boundQueryBytes(array $ids): int
    {
        $probe = (clone $this)->whereKey($ids);
        $connection = $probe->getConnection();

        if (! $connection instanceof ClickhouseConnection) {
            return 0;
        }

        $bound = $connection->getClient()->bindParams($probe->toSql(), $probe->getBindings());

        return strlen($bound.' FORMAT JSON');
    }
}
