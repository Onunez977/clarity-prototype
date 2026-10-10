# Laravel ClickHouse Eloquent

ClickHouse integration for Laravel with an Eloquent-like ORM and query builder.

Uses a built-in HTTP client (cURL) — no external ClickHouse libraries required.

## Prerequisites

- PHP 8.1+
- Laravel 9+
- ClickHouse server
- ext-curl, ext-json

## Installation

**1.** Install via composer

```sh
composer require insight-lpr/laravel-clickhouse
```
**2.** Add a new connection in `config/database.php`:

```php
'clickhouse' => [
    'driver' => 'clickhouse',
    'host' => env('CLICKHOUSE_HOST'),
    'port' => env('CLICKHOUSE_PORT', '8123'),
    'database' => env('CLICKHOUSE_DATABASE', 'default'),
    'username' => env('CLICKHOUSE_USERNAME', 'default'),
    'password' => env('CLICKHOUSE_PASSWORD', ''),
    'timeout_connect' => env('CLICKHOUSE_TIMEOUT_CONNECT', 2),
    'timeout_query' => env('CLICKHOUSE_TIMEOUT_QUERY', 2),
    'https' => (bool) env('CLICKHOUSE_HTTPS', null),
    'retries' => env('CLICKHOUSE_RETRIES', 0),
    'settings' => [ // optional
        'max_partitions_per_insert_block' => 300,
    ],
],
```

Then add to your `.env`:

```dotenv
CLICKHOUSE_HOST=localhost
CLICKHOUSE_PORT=8123
CLICKHOUSE_DATABASE=default
CLICKHOUSE_USERNAME=default
CLICKHOUSE_PASSWORD=
# only if you use HTTPS
CLICKHOUSE_HTTPS=true
```

**3.** Add the service provider to `config/app.php` (before `AppServiceProvider`):

```php
\LaravelClickhouseEloquent\ClickhouseServiceProvider::class,
```

## Usage

### Two ways to use ClickHouse with Laravel

#### Option A: Eloquent Model (recommended for apps already using Eloquent)

```php
use Illuminate\Database\Eloquent\Model;

class ScanHot extends Model
{
    protected $connection = 'clickhouse';
    protected $table = 'scans_hot';
}

// Standard Eloquent query builder
$scans = ScanHot::query()
    ->where('org_id', 123)
    ->where('scanned_at', '>', '2025-01-01')
    ->orderByDesc('scanned_at')
    ->limit(10)
    ->get();
```

#### Option B: BaseModel (lightweight ORM with ClickHouse-specific features)

```php
use LaravelClickhouseEloquent\BaseModel;

class MyTable extends BaseModel
{
    // Optional — derived from class name: MyTable => my_tables
    protected $table = 'my_table';
}
```

#### Direct client access

```php
$db = DB::connection('clickhouse')->getClient();
$rows = $db->select('SELECT * FROM my_table LIMIT 10');
$db->write('TRUNCATE TABLE my_table');
```

### Migrations

```php
class CreateMyTable extends \LaravelClickhouseEloquent\Migration
{
    public function up()
    {
        static::write('
            CREATE TABLE my_table (
                id UInt32,
                created_at DateTime,
                field_one String,
                field_two Int32
            )
            ENGINE = MergeTree()
            ORDER BY (id)
        ');
    }

    public function down()
    {
        static::write('DROP TABLE my_table');
    }
}
```

### Inserting data

```php
// Single row
$model = MyTable::create(['field_one' => 'value', 'field_two' => 1]);

// Or build and save
$model = new MyTable();
$model->field_one = 'value';
$model->field_two = 1;
$model->save();

// Bulk insert (associative)
MyTable::insertAssoc([
    ['field_one' => 'a', 'field_two' => 1],
    ['field_one' => 'b', 'field_two' => 2],
]);

// Bulk insert (positional)
MyTable::insertBulk([['a', 1], ['b', 2]], ['field_one', 'field_two']);
```

### Query builder

```php
use LaravelClickhouseEloquent\RawColumn;

$rows = MyTable::select(['field_one', new RawColumn('sum(field_two)', 'total')])
    ->where('created_at', '>', '2025-01-01')
    ->groupBy('field_one')
    ->settings(['max_threads' => 3])
    ->getRows();
```

### ClickHouse-specific query methods

These methods are available on the Builder (via `BaseModel::select()`, `BaseModel::where()`, etc.):

```php
// FINAL — deduplication for ReplacingMergeTree
MyTable::select()->final()->getRows();

// SAMPLE — approximate query processing
MyTable::select()->sample(0.1)->getRows();

// PREWHERE — filter before reading columns (optimization)
MyTable::select()
    ->preWhere('status', 1)
    ->preWhereIn('org_id', [1, 2, 3])
    ->preWhereBetween('created_at', ['2025-01-01', '2025-12-31'])
    ->preWhereRaw('toDate(created_at) = today()')
    ->getRows();

// LIMIT BY — limit rows per group
MyTable::select()
    ->orderByDesc('created_at')
    ->limitBy(5, 'org_id')
    ->getRows();

// ARRAY JOIN
MyTable::select()->arrayJoin('tags')->getRows();
MyTable::select()->leftArrayJoin('tags')->getRows();

// SETTINGS
MyTable::select()->settings(['max_threads' => 2])->getRows();
```

### Common Table Expressions (CTEs)

```php
use LaravelClickhouseEloquent\Builder;
use LaravelClickhouseEloquent\RawColumn;

// CTE with subquery
$rows = MyTable::select()
    ->withCte('active', function (Builder $q) {
        $q->select('*')->from('my_table')->where('status', 1);
    })
    ->from('active')
    ->getRows();

// CTE with expression alias
$rows = MyTable::select()
    ->withCteExpression('threshold', 100)
    ->where('score', '>', new RawColumn('threshold'))
    ->getRows();

// CTE with raw SQL
$rows = MyTable::select()
    ->withCte('filtered', 'SELECT * FROM my_table WHERE score > 100')
    ->from('filtered')
    ->getRows();
```

CTEs also work on the Eloquent query builder path via the `clickhouseCtes` property:

```php
$builder = ScanHot::query()->getQuery();
$builder->clickhouseCtes = [
    ['type' => 'expression', 'name' => 'target_org', 'sql' => '123'],
    ['type' => 'subquery', 'name' => 'filtered', 'sql' => 'SELECT * FROM scans_hot WHERE org_id = target_org'],
];
```

### Raw SQL with bindings

Both `?` and legacy `:N` placeholders are supported:

```php
// Standard ? placeholders
$rows = DB::connection('clickhouse')->select(
    'SELECT * FROM my_table WHERE org_id = ? AND created_at > ?',
    [123, '2025-01-01']
);

// Legacy :N placeholders (backward compatible)
$rows = DB::connection('clickhouse')->select(
    'SELECT * FROM my_table WHERE org_id = :0 AND created_at > :1',
    [123, '2025-01-01']
);
```

## Advanced usage

### Columns casting

Before insertion, columns are converted to the data type specified in `$casts`.
Supported cast types: `boolean`.

```php
class MyTable extends BaseModel
{
    protected $casts = ['is_active' => 'boolean'];
}

MyTable::insertAssoc([['field_one' => 1, 'is_active' => false]]);
```

### Events

Events work like [Eloquent model events](https://laravel.com/docs/eloquent#events).
Available events: **creating**, **created**, **saved**

### Retries

Enable automatic retries on non-200 responses (e.g., network issues):

```dotenv
CLICKHOUSE_RETRIES=2
```

- `0` = one attempt, no retries (default)
- `1` = one attempt + 1 retry (2 total)
- Client errors (4xx) are not retried

### Chunking

```php
MyTable::select(['field_one', 'field_two'])
    ->chunk(30, function ($rows) {
        foreach ($rows as $row) {
            echo $row['field_two'] . "\n";
        }
    });
```

### Pagination

```php
$paginator = MyTable::select()->paginate(
    perPage: 25,
    columns: ['*'],
    pageName: 'page',
    page: 1,
    total: $knownTotal  // pass total to avoid COUNT query
);
```

### Buffer engine

See https://clickhouse.com/docs/en/engines/table-engines/special/buffer/

```php
class MyTable extends BaseModel
{
    protected $table = 'my_table';           // SELECT reads from here
    protected $tableForInserts = 'my_table_buffer';  // INSERT writes here
    protected $tableSources = 'my_table';    // DELETE/UPDATE/OPTIMIZE target
}
```

### OPTIMIZE and TRUNCATE

```php
MyTable::optimize($final = false, $partition = null);
MyTable::truncate();
```

### Deletions and Updates

These use `ALTER TABLE ... DELETE/UPDATE` (heavy operations, not for frequent use):

```php
MyTable::where('field_one', 123)->delete();
MyTable::where('field_one', 123)->update(['field_two' => 'new_val']);

// With expressions
MyTable::where('field_one', 123)
    ->update(['field_two' => new RawColumn("concat(field_two, '_suffix')")]);
```

### Array data type

```php
use LaravelClickhouseEloquent\Expressions\InsertArray;

MyTable::insertAssoc([[1, 'str', new InsertArray(['a', 'b'])]]);
```
