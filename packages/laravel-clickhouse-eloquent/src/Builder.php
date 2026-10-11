<?php

declare(strict_types=1);

namespace LaravelClickhouseEloquent;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Query\Builder as LaravelBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use LaravelClickhouseEloquent\Exceptions\QueryException;

class Builder extends LaravelBuilder
{
    /** @var string|null Table for DELETE/UPDATE/OPTIMIZE */
    protected $tableSources;

    /** @var array SETTINGS clause key-value pairs */
    protected $settings = [];

    /** @var array CTE definitions */
    protected $ctes = [];

    // ClickHouse-specific query state

    /** @var bool Use FINAL modifier after FROM */
    public bool $useFinal = false;

    /** @var float|null SAMPLE coefficient */
    public ?float $sampleCoefficient = null;

    /** @var array PREWHERE clauses */
    public array $preWheres = [];

    /** @var int|null LIMIT BY count */
    public ?int $limitByCount = null;

    /** @var array LIMIT BY columns */
    public array $limitByColumns = [];

    /** @var string|null ARRAY JOIN column */
    public ?string $arrayJoinColumn = null;

    /** @var string|null ARRAY JOIN type (LEFT, INNER, or null) */
    public ?string $arrayJoinType = null;

    public function __construct(Connection $connection)
    {
        parent::__construct($connection, $connection->getQueryGrammar());
    }

    // -----------------------------------------------------------------
    // SETTINGS
    // -----------------------------------------------------------------

    /**
     * Set the SETTINGS clause for the SELECT statement.
     * @link https://clickhouse.com/docs/en/sql-reference/statements/select#settings-in-select-query
     */
    public function settings(array $settings): self
    {
        $this->settings = $settings;
        return $this;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    // -----------------------------------------------------------------
    // ClickHouse-specific clauses
    // -----------------------------------------------------------------

    /**
     * Add FINAL modifier to SELECT (for ReplacingMergeTree deduplication).
     */
    public function final(bool $final = true): self
    {
        $this->useFinal = $final;
        return $this;
    }

    /**
     * Add SAMPLE clause for approximate query processing.
     */
    public function sample(float $coefficient): self
    {
        $this->sampleCoefficient = $coefficient;
        return $this;
    }

    /**
     * Add PREWHERE clause (ClickHouse optimization — filters before reading columns).
     */
    public function preWhere(string|\Closure $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): self
    {
        if ($column instanceof \Closure) {
            // TODO: nested prewhere groups could be supported here
            return $this;
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->preWheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
        ];

        $this->addBinding($value, 'where');

        return $this;
    }

    /**
     * Add a raw PREWHERE expression.
     */
    public function preWhereRaw(string $expression): self
    {
        $this->preWheres[] = [
            'type' => 'raw',
            'sql' => $expression,
            'boolean' => 'and',
        ];

        return $this;
    }

    /**
     * Add PREWHERE IN clause.
     */
    public function preWhereIn(string $column, array $values, string $boolean = 'and', bool $not = false): self
    {
        $this->preWheres[] = [
            'type' => 'in',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
            'not' => $not,
        ];

        foreach ($values as $val) {
            $this->addBinding($val, 'where');
        }

        return $this;
    }

    /**
     * Add PREWHERE NOT IN clause.
     */
    public function preWhereNotIn(string $column, array $values, string $boolean = 'and'): self
    {
        return $this->preWhereIn($column, $values, $boolean, true);
    }

    /**
     * Add PREWHERE BETWEEN clause.
     */
    public function preWhereBetween(string $column, array $values, string $boolean = 'and', bool $not = false): self
    {
        $this->preWheres[] = [
            'type' => 'between',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
            'not' => $not,
        ];

        $this->addBinding($values[0], 'where');
        $this->addBinding($values[1], 'where');

        return $this;
    }

    /**
     * Add PREWHERE NOT BETWEEN clause.
     */
    public function preWhereNotBetween(string $column, array $values, string $boolean = 'and'): self
    {
        return $this->preWhereBetween($column, $values, $boolean, true);
    }

    /**
     * Add LIMIT BY clause (ClickHouse-specific: limits rows per group).
     */
    public function limitBy(int $count, string ...$columns): self
    {
        $this->limitByCount = $count;
        $this->limitByColumns = $columns;

        return $this;
    }

    /**
     * Add ARRAY JOIN clause.
     */
    public function arrayJoin(string $column, ?string $type = null): self
    {
        $this->arrayJoinColumn = $column;
        $this->arrayJoinType = $type;

        return $this;
    }

    /**
     * Add LEFT ARRAY JOIN clause.
     */
    public function leftArrayJoin(string $column): self
    {
        return $this->arrayJoin($column, 'LEFT');
    }

    // -----------------------------------------------------------------
    // Query execution
    // -----------------------------------------------------------------

    /**
     * Execute the query and return rows as an array of associative arrays.
     */
    public function getRows(): array
    {
        return $this->connection->select($this->toSql(), $this->getBindings());
    }

    /**
     * For delete query
     */
    public function setSourcesTable(string $table): self
    {
        $this->tableSources = $table;
        return $this;
    }

    /**
     * Note! This is a heavy operation not designed for frequent use.
     */
    public function delete($id = null): bool
    {
        $table = $this->tableSources ?? $this->from;
        $wheres = $this->grammar->compileWheres($this);
        $sql = "ALTER TABLE {$table} DELETE {$wheres}";

        return $this->connection->statement($sql, $this->getBindings());
    }

    /**
     * Note! This is a heavy operation not designed for frequent use.
     */
    public function update(array $values): bool
    {
        if (empty($values)) {
            throw QueryException::cannotUpdateEmptyValues();
        }

        $table = $this->tableSources ?? $this->from;
        $set = [];
        foreach ($values as $key => $value) {
            $set[] = "`{$key}` = " . $this->grammar->wrap($value);
        }
        $wheres = $this->grammar->compileWheres($this);
        $sql = "ALTER TABLE {$table} UPDATE " . implode(', ', $set) . ' ' . $wheres;

        return $this->connection->statement($sql, $this->getBindings());
    }

    public function newQuery(): self
    {
        return new static($this->connection);
    }

    // -----------------------------------------------------------------
    // Pagination
    // -----------------------------------------------------------------

    /**
     * Paginate the query using Laravel-style paginator.
     */
    public function paginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null, $total = null): LengthAwarePaginator
    {
        $page = $page ?? Paginator::resolveCurrentPage($pageName);
        $page = max(1, (int) $page);

        $total = $this->resolveValue($total);
        if (is_null($total)) {
            $total = $this->getCountForPagination();
        }
        $total = (int) $total;

        $perPage = $this->resolveValue($perPage, [$total]);
        $perPage = (int) $perPage;
        $perPage = $perPage > 0 ? $perPage : 15;

        $items = $total ? $this->forPage($page, $perPage)->getRows() : [];

        return new LengthAwarePaginator(
            new Collection($items),
            $total,
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]
        );
    }

    /**
     * Chunk the results of the query.
     */
    public function chunk($count, callable $callback): bool
    {
        $page = 1;
        do {
            $rows = $this->forPage($page, $count)->getRows();
            $callback(new Collection($rows));
            $page++;
        } while (!empty($rows));

        return true;
    }

    /**
     * Resolve a value or callable helper.
     */
    protected function resolveValue(mixed $value, array $arguments = []): mixed
    {
        return is_callable($value) ? $value(...$arguments) : $value;
    }

    // -----------------------------------------------------------------
    // CTEs
    // -----------------------------------------------------------------

    /**
     * Get the CTEs for the query.
     */
    public function getCtes(): array
    {
        return $this->ctes;
    }

    /**
     * Add a Common Table Expression (CTE) subquery.
     *
     * WITH name AS (SELECT ...)
     */
    public function withCte(string $name, \Closure|self|string $query): self
    {
        $sql = match (true) {
            $query instanceof \Closure => $this->compileClosureToSql($query),
            $query instanceof self => $query->toSql(),
            default => $query,
        };

        $this->ctes[] = [
            'name' => $name,
            'type' => 'subquery',
            'sql' => $sql,
        ];

        return $this;
    }

    /**
     * Compile a closure to SQL by executing it with a fresh Builder.
     */
    protected function compileClosureToSql(\Closure $callback): string
    {
        $builder = new static($this->connection);
        $callback($builder);
        return $builder->toSql();
    }

    /**
     * Add a Common Table Expression (CTE) expression alias.
     *
     * WITH value AS name
     */
    public function withCteExpression(string $name, mixed $value): self
    {
        $sql = match (true) {
            $value instanceof \Closure => '(' . $this->compileClosureToSql($value) . ')',
            $value instanceof self => '(' . $value->toSql() . ')',
            $value instanceof Expression => $value->getValue($this->grammar),
            is_string($value) => "'" . addslashes($value) . "'",
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };

        $this->ctes[] = [
            'name' => $name,
            'type' => 'expression',
            'sql' => $sql,
        ];

        return $this;
    }
}
