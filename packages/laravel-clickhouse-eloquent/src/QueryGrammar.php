<?php

declare(strict_types=1);

namespace LaravelClickhouseEloquent;

use Illuminate\Database\Query\Builder as LaravelBuilder;
use Illuminate\Database\Query\Grammars\Grammar;

class QueryGrammar extends Grammar
{
    /**
     * Wrap a single string in backtick-quoted identifier (ClickHouse-compatible).
     */
    protected function wrapValue($value)
    {
        return $value === '*' ? $value : '`' . str_replace('`', '``', $value) . '`';
    }

    /** @inheritDoc */
    protected function compileDeleteWithoutJoins(LaravelBuilder $query, $table, $where): string
    {
        return "alter table {$table} delete {$where}";
    }

    /**
     * Compile a select query into SQL with ClickHouse-specific clause support.
     */
    public function compileSelect(LaravelBuilder $query): string
    {
        $sql = parent::compileSelect($query);

        // ClickHouse-specific clauses from our Builder
        if ($query instanceof Builder) {
            $sql = $this->injectClickhouseClauses($query, $sql);
        }

        // CTEs added via dynamic property (Eloquent wrapper path)
        if (isset($query->clickhouseCtes) && !empty($query->clickhouseCtes)) {
            $sql = $this->compileCtes($query->clickhouseCtes) . $sql;
        }

        return $sql;
    }

    /**
     * Inject ClickHouse-specific clauses into the compiled SQL.
     */
    protected function injectClickhouseClauses(Builder $query, string $sql): string
    {
        // CTEs from our Builder
        if (!empty($query->getCtes())) {
            $sql = $this->compileCtes($query->getCtes()) . $sql;
        }

        // FINAL and SAMPLE — injected after the FROM clause
        $afterFrom = '';
        if ($query->useFinal) {
            $afterFrom .= ' FINAL';
        }
        if ($query->sampleCoefficient !== null) {
            $afterFrom .= ' SAMPLE ' . $query->sampleCoefficient;
        }

        // ARRAY JOIN — injected after FROM (and FINAL/SAMPLE)
        if ($query->arrayJoinColumn !== null) {
            $type = $query->arrayJoinType ? $query->arrayJoinType . ' ' : '';
            $afterFrom .= ' ' . $type . 'ARRAY JOIN ' . $this->wrap($query->arrayJoinColumn);
        }

        if ($afterFrom !== '') {
            $sql = $this->insertAfterFrom($sql, $afterFrom);
        }

        // PREWHERE — injected before WHERE
        if (!empty($query->preWheres)) {
            $preWhereSql = $this->compilePreWheres($query);
            $sql = $this->insertPreWhere($sql, $preWhereSql);
        }

        // LIMIT BY — injected before the final LIMIT
        if ($query->limitByCount !== null) {
            $limitBySql = $this->compileLimitBy($query);
            $sql = $this->insertLimitBy($sql, $limitBySql);
        }

        // SETTINGS — appended at the very end
        if (!empty($query->getSettings())) {
            $sql .= ' ' . $this->compileSettings($query->getSettings());
        }

        return $sql;
    }

    /**
     * Compile the CTEs into a WITH clause.
     */
    protected function compileCtes(array $ctes): string
    {
        $parts = [];
        foreach ($ctes as $cte) {
            if ($cte['type'] === 'expression') {
                $parts[] = "{$cte['sql']} AS {$cte['name']}";
            } else {
                $parts[] = "{$cte['name']} AS ({$cte['sql']})";
            }
        }

        return 'WITH ' . implode(', ', $parts) . ' ';
    }

    /**
     * Compile PREWHERE clauses.
     */
    protected function compilePreWheres(Builder $query): string
    {
        $clauses = [];
        foreach ($query->preWheres as $i => $preWhere) {
            $connector = $i === 0 ? '' : ' ' . $preWhere['boolean'] . ' ';

            if ($preWhere['type'] === 'raw') {
                $clauses[] = $connector . $preWhere['sql'];
            } elseif ($preWhere['type'] === 'basic') {
                $clauses[] = $connector . $this->wrap($preWhere['column']) . ' ' . $preWhere['operator'] . ' ' . $this->parameter($preWhere['value']);
            } elseif ($preWhere['type'] === 'in') {
                $values = implode(', ', array_map([$this, 'parameter'], $preWhere['values']));
                $not = !empty($preWhere['not']) ? 'not ' : '';
                $clauses[] = $connector . $this->wrap($preWhere['column']) . ' ' . $not . 'in (' . $values . ')';
            } elseif ($preWhere['type'] === 'between') {
                $not = !empty($preWhere['not']) ? 'not ' : '';
                $clauses[] = $connector . $this->wrap($preWhere['column']) . ' ' . $not . 'between ' . $this->parameter($preWhere['values'][0]) . ' and ' . $this->parameter($preWhere['values'][1]);
            }
        }

        return 'PREWHERE ' . implode('', $clauses);
    }

    /**
     * Compile LIMIT BY clause.
     */
    protected function compileLimitBy(Builder $query): string
    {
        $columns = implode(', ', array_map([$this, 'wrap'], $query->limitByColumns));

        return "LIMIT {$query->limitByCount} BY {$columns}";
    }

    /**
     * Compile the SETTINGS clause.
     */
    protected function compileSettings(array $settings): string
    {
        $parts = [];
        foreach ($settings as $key => $value) {
            $parts[] = is_int($value) ? "{$key}={$value}" : "{$key}='{$value}'";
        }

        return 'SETTINGS ' . implode(', ', $parts);
    }

    /**
     * Insert text after the FROM clause in compiled SQL.
     */
    private function insertAfterFrom(string $sql, string $insert): string
    {
        // Find the FROM clause boundary — it ends before join/where/group/having/order/limit/union or end of string
        if (preg_match('/\b(from\s+\S+(?:\s+as\s+\S+)?)/i', $sql, $match, PREG_OFFSET_CAPTURE)) {
            $pos = $match[1][1] + strlen($match[1][0]);
            return substr($sql, 0, $pos) . $insert . substr($sql, $pos);
        }

        return $sql . $insert;
    }

    /**
     * Insert PREWHERE before the WHERE clause.
     */
    private function insertPreWhere(string $sql, string $preWhereSql): string
    {
        // Find " where " boundary
        if (preg_match('/\bwhere\b/i', $sql, $match, PREG_OFFSET_CAPTURE)) {
            $pos = $match[0][1];
            return substr($sql, 0, $pos) . $preWhereSql . ' ' . substr($sql, $pos);
        }

        // No WHERE clause — insert PREWHERE at end (before GROUP BY, ORDER BY, LIMIT, etc.)
        foreach (['group by', 'having', 'order by', 'limit', 'union'] as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/i', $sql, $match, PREG_OFFSET_CAPTURE)) {
                $pos = $match[0][1];
                return substr($sql, 0, $pos) . $preWhereSql . ' ' . substr($sql, $pos);
            }
        }

        return $sql . ' ' . $preWhereSql;
    }

    /**
     * Insert LIMIT BY before the final LIMIT clause.
     */
    private function insertLimitBy(string $sql, string $limitBySql): string
    {
        // Find the last "limit" keyword (not inside a subquery)
        if (preg_match('/\blimit\s+\d+/i', $sql, $match, PREG_OFFSET_CAPTURE)) {
            $pos = $match[0][1];
            return substr($sql, 0, $pos) . $limitBySql . ' ' . substr($sql, $pos);
        }

        return $sql . ' ' . $limitBySql;
    }
}
