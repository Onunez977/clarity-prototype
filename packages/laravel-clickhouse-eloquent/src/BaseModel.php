<?php

declare(strict_types=1);

namespace LaravelClickhouseEloquent;

use Exception;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Concerns\HasAttributes;
use Illuminate\Database\Eloquent\Concerns\HasEvents;
use Illuminate\Database\Eloquent\Concerns\HidesAttributes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BaseModel
{
    use HasAttributes;
    use HidesAttributes;
    use HasEvents;
    use WithClient;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table;

    /**
     * Use this only when you have Buffer table engine for inserts
     * @link https://clickhouse.tech/docs/ru/engines/table-engines/special/buffer/
     *
     * @var string
     */
    protected $tableForInserts;

    /**
     * Use this field for OPTIMIZE TABLE OR ALTER TABLE (also DELETE) queries
     *
     * @var string
     */
    protected $tableSources;

    /**
     * Indicates if the model exists.
     *
     * @var bool
     */
    public $exists = false;

    /**
     * Indicates if the model was inserted during the current request lifecycle.
     *
     * @var bool
     */
    public $wasRecentlyCreated = false;

    /**
     * The event dispatcher instance.
     *
     * @var Dispatcher
     */
    protected static $dispatcher;

    /**
     * The name of the database connection to use.
     *
     * @var string
     */
    protected $connection = Connection::DEFAULT_NAME;

    /**
     * Determine if an attribute or relation exists on the model.
     */
    public function __isset($key)
    {
        if (array_key_exists($key, $this->attributes)) {
            return true;
        }

        $accessor = 'get'.Str::studly($key).'Attribute';
        if (method_exists($this, $accessor)) {
            return true;
        }

        if (array_key_exists($key, $this->relations)) {
            return true;
        }

        return false;
    }

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return $this->table ?? Str::snake(Str::pluralStudly(class_basename($this)));
    }

    /**
     * Get the table name for insert queries
     */
    public function getTableForInserts(): string
    {
        return $this->tableForInserts ?? $this->getTable();
    }

    /**
     * Use this field for OPTIMIZE TABLE OR ALTER TABLE (also DELETE) queries
     */
    public function getTableSources(): string
    {
        return $this->tableSources ?? $this->getTable();
    }

    /**
     * Create and return an un-saved model instance.
     */
    public static function make(array $attributes = [])
    {
        $model = new static;
        $model->fill($attributes);

        return $model;
    }

    /**
     * Save a new model and return the instance.
     */
    public static function create(array $attributes = [])
    {
        $model = static::make($attributes);
        $model->fireModelEvent('creating', false);

        if ($model->save()) {
            $model->wasRecentlyCreated = true;

            $model->fireModelEvent('created', false);
        }

        return $model;
    }

    /**
     * Fill the model with an array of attributes.
     */
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    /**
     * Save the model to the database.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new Exception("Clickhouse does not allow update rows");
        }
        $this->exists = static::insertAssoc([$this->getAttributes()]);
        $this->fireModelEvent('saved', false);

        return $this->exists;
    }

    /**
     * Bulk insert into Clickhouse database
     * @deprecated use insertBulk
     */
    public static function insert(array $rows): bool
    {
        $instance = new static();

        return $instance->getThisClient()->insert($instance->getTableForInserts(), $rows);
    }

    /**
     * Bulk insert into Clickhouse database
     * @example MyModel::insertBulk([['model 1', 1], ['model 2', 2]], ['model_name', 'some_param']);
     */
    public static function insertBulk(array $rows, array $columns = []): bool
    {
        $instance = new static();
        if ($castsAssoc = (new static())->casts) {
            $casts = [];
            foreach ($castsAssoc as $castColumn => $castType) {
                if ($index = array_search($castColumn, $columns)) {
                    $casts[$index] = $castType;
                }
            }
            foreach ($rows as &$row) {
                $row = static::castRow($row, $casts);
            }
        }

        return $instance->getThisClient()->insert($instance->getTableForInserts(), $rows, $columns);
    }

    /**
     * Prepare each row by calling static::prepareFromRequest to bulk insert into database
     */
    public static function prepareAndInsert(array $rows, array $columns = []): bool
    {
        $rows     = array_map('static::prepareFromRequest', $rows, $columns);
        $instance = new static();

        return $instance->getThisClient()->insert($instance->getTableForInserts(), $rows, $columns);
    }

    /**
     * Bulk insert rows as associative array into Clickhouse database
     * @example MyModel::insertAssoc([['model_name' => 'model 1', 'some_param' => 1], ['model_name' => 'model 2', 'some_param' => 2]]);
     */
    public static function insertAssoc(array $rows): bool
    {
        $rows = array_values($rows);
        if (isset($rows[0]) && isset($rows[1])) {
            $keys = array_keys($rows[0]);
            foreach ($rows as &$row) {
                $row = array_replace(array_flip($keys), $row);
            }
        }
        if ($casts = (new static())->casts) {
            foreach ($rows as &$row) {
                $row = static::castRow($row, $casts);
            }
        }
        $instance = new static();

        return $instance->getThisClient()->insertAssocBulk($instance->getTableForInserts(), $rows);
    }

    /**
     * Prepare each row by calling static::prepareAssocFromRequest to bulk insert into database
     */
    public static function prepareAndInsertAssoc(array $rows): bool
    {
        $rows = array_map('static::prepareAssocFromRequest', $rows);

        return static::insertAssoc($rows);
    }

    /**
     * Prepare row to insert into DB, non-associative array
     * Need to overwrite in nested models
     */
    public static function prepareFromRequest(array $row, array $columns = []): array
    {
        return $row;
    }

    /**
     * Prepare row to insert into DB, associative array
     * Need to overwrite in nested models
     */
    public static function prepareAssocFromRequest(array $row): array
    {
        return $row;
    }

    protected static function castRow(array $row, array $casts): array
    {
        foreach ($casts as $index => $castType) {
            $value = $row[$index];
            if ('boolean' == $castType) {
                $value = (int)(bool)$value;
            }
            $row[$index] = $value;
        }

        return $row;
    }

    /**
     * @param string|array|RawColumn $select optional = ['*']
     * @return Builder
     */
    public static function select($select = ['*']): Builder
    {
        $instance = new static();

        return $instance->newQuery()->select($select)->from($instance->getTable());
    }

    /**
     * @return Builder
     */
    protected function newQuery(): Builder
    {
        return new Builder(DB::connection($this->connection));
    }

    /**
     * Necessary stub for HasAttributes trait
     */
    public function getCasts(): array
    {
        return $this->casts;
    }

    /**
     * Necessary stub for HasAttributes trait
     */
    public function usesTimestamps(): bool
    {
        return false;
    }

    /**
     * Necessary stub for HasAttributes trait
     */
    public function getRelationValue($key)
    {
        return null;
    }

    /**
     * Dynamically retrieve attributes on the model.
     */
    public function __get(string $key)
    {
        return $this->getAttribute($key);
    }

    /**
     * Dynamically set attributes on the model.
     */
    public function __set(string $key, $value): void
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Optimize table. Using for ReplacingMergeTree, etc.
     * @source https://clickhouse.tech/docs/ru/sql-reference/statements/optimize/
     */
    public static function optimize(bool $final = false, ?string $partition = null): bool
    {
        $instance = new static();
        $sql      = "OPTIMIZE TABLE ".$instance->getTableSources();
        if ($partition) {
            $sql .= " PARTITION '$partition'";
        }
        if ($final) {
            $sql .= " FINAL";
        }

        return $instance->getThisClient()->write($sql);
    }

    public static function truncate(): bool
    {
        $instance = new static();

        return $instance->getThisClient()->write('TRUNCATE TABLE '.$instance->getTableSources());
    }

    /**
     * @param string|\Closure $column
     * @param int|float|string|null $operator or $value
     * @param int|float|string|null $value
     * @param string $concatOperator 'and' or 'or'
     * @return Builder
     */
    public static function where(
        $column,
        $operator = null,
        $value = null,
        string $concatOperator = 'and'
    ): Builder {
        $instance = new static();
        $builder  = $instance->newQuery()->select(['*'])
            ->from($instance->getTable())
            ->setSourcesTable($instance->getTableSources());
        if (is_null($value)) {
            $builder->where($column, $operator);
        } else {
            $builder->where($column, $operator, $value, $concatOperator);
        }

        return $builder;
    }

    /**
     * @param string $expression
     * @return Builder
     */
    public static function whereRaw(string $expression): Builder
    {
        $instance = new static();

        return $instance->newQuery()->select(['*'])
            ->from($instance->getTable())
            ->setSourcesTable($instance->getTableSources())
            ->whereRaw($expression);
    }

    /**
     * Get the dynamic relation resolver if defined or inherited, or return null.
     */
    public function relationResolver($class, $key): mixed
    {
        return null;
    }

    /**
     * Determine if the given relation is loaded.
     */
    public function relationLoaded($key): bool
    {
        return false;
    }

    /**
     * Determine if accessing missing attributes is disabled.
     */
    public static function preventsAccessingMissingAttributes(): bool
    {
        return false;
    }

    /**
     * Begin querying the model.
     */
    public static function query(): Builder
    {
        return (new static)->newQuery();
    }
}
