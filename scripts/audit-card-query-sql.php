<?php

/**
 * Proves the SQL the Company Metrics "Query" section DISPLAYS is byte-identical to the SQL the
 * card actually sends to ClickHouse, for all 16 cards x 4 date modes.
 *
 * This cannot be a PHPUnit test: the pgsql test bridge is a schema bridge, not a dialect
 * translator, so ClickHouse-only syntax (`GROUP BY ... WITH ROLLUP`, `uniqExactMerge`) is a
 * Postgres syntax error and nothing executes to compare against. It needs a real ClickHouse.
 *
 * It works by swapping the connection's HTTP client for a recorder, so what it captures is the
 * exact string handed to the wire -- after bindParams(), not a reconstruction.
 *
 * Run against an environment with live ClickHouse credentials:
 *
 *   AUDIT_USER_ID=6 php artisan tinker --execute='require "scripts/audit-card-query-sql.php";'
 *   AUDIT_CARDS=scan-count,live-hits AUDIT_USER_ID=6 php artisan tinker --execute='...'
 *
 * Every row must read EXACT. A DIVERGES row means the section is showing a query that is not
 * the one producing the number above it. NOTE: a row that also reports ERR: is unreliable -- a
 * ClickHouse timeout aborts the card mid-path, so later statements never run and look missing.
 * Re-run those cards before believing a divergence.
 */

use App\Filament\Widgets\Dashboard\CompanyMetricsOverview;
use App\Filament\Widgets\Dashboard\Concerns\SharedHitStatsData;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LaravelClickhouseEloquent\Connection;

if (! class_exists('CardQuerySqlRecorder')) {
    eval('
    class CardQuerySqlRecorder extends \LaravelClickhouseEloquent\ClickhouseHttpClient {
        public static array $recorded = [];
        public function select(string $sql, array $bindings = []): array {
            self::$recorded[] = $this->bindParams($sql, $bindings);
            return parent::select($sql, $bindings);
        }
    }');
}

$normalise = fn (string $sql): string => trim(preg_replace('/\s+/', ' ', $sql));

$resetMemos = function () {
    cache()->flush();

    foreach (CompanyMetricsOverview::cards() as $class) {
        if (! in_array(SharedHitStatsData::class, class_uses_recursive($class), true)) {
            continue;
        }

        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            if ($property->isStatic() && str_starts_with($property->getName(), 'shared')) {
                $property->setAccessible(true);
                $property->setValue(null, []);
            }
        }
    }
};

$build = function (string $class, string $mode) {
    $card = new $class;
    $card->date = $mode;

    if (property_exists($card, 'filters')) {
        $card->filters = ['date_toggles' => $mode];
    }

    return $card;
};

auth()->login(User::findOrFail((int) (getenv('AUDIT_USER_ID') ?: 1)));

$property = new ReflectionProperty(Connection::class, 'client');
$property->setAccessible(true);
$property->setValue(
    DB::connection('clarity_clickhouse'),
    new CardQuerySqlRecorder(config('database.connections.clarity_clickhouse'))
);

$only = array_filter(explode(',', getenv('AUDIT_CARDS') ?: ''));
$exact = 0;
$diverged = 0;

foreach (CompanyMetricsOverview::cards() as $key => $class) {
    if ($only !== [] && ! in_array($key, $only, true)) {
        continue;
    }

    foreach (['today', 'last_7d', 'this_month', 'last_month'] as $mode) {
        $resetMemos();
        $declared = array_map(
            fn ($statement) => $normalise($statement->sql),
            $build($class, $mode)->getCardQueryStatements()
        );

        $resetMemos();
        CardQuerySqlRecorder::$recorded = [];
        $error = null;

        try {
            $build($class, $mode)->toStat();
        } catch (Throwable $e) {
            $error = get_class($e).': '.substr($e->getMessage(), 0, 90);
        }

        $executed = array_map($normalise, CardQuerySqlRecorder::$recorded);
        $missing = array_values(array_filter($declared, fn ($sql) => ! in_array($sql, $executed, true)));

        $missing === [] ? $exact++ : $diverged++;

        printf("%-34s %-11s %-14s decl=%d exec=%d %s\n",
            $key, $mode, $missing === [] ? 'EXACT' : 'DIVERGES('.count($missing).'/'.count($declared).')',
            count($declared), count($executed), $error ? 'ERR:'.$error : '');

        foreach ($missing as $sql) {
            echo '    DECLARED-NOT-RUN: '.substr($sql, 0, 160)."\n";
        }
    }
}

echo "\n{$exact} exact, {$diverged} diverged\n";
