<?php

namespace Database\Seeders;

use App\Models\Kit;
use App\Models\KitSettings;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Projects the seeded Postgres rows into a LOCAL ClickHouse the way CDC would:
 * 1:1 zod.public_* mirrors plus the denormalized clarity.* hot/cold tables.
 * Every target is truncated first, so a re-run is a clean re-sync.
 * Only app:preview-bootstrap calls this — it owns the host guard.
 */
class CloudClickhouseSeeder extends Seeder
{
    private const CONNECTION = 'clarity_clickhouse_write';

    private const HOT_WINDOW_DAYS = 90;

    /**
     * @var array<string, string> Postgres public table => ClickHouse CDC mirror
     */
    private const MIRRORS = [
        'orgs' => 'zod.public_orgs',
        'org_meta' => 'zod.public_org_meta',
        'kits' => 'zod.public_kits',
        'integrations' => 'zod.public_integrations',
        'approval_list' => 'zod.public_approval_list',
        'hotlist' => 'zod.public_hotlist',
        'hotlist_meta' => 'zod.public_hotlist_meta',
        'address' => 'zod.public_address',
        'hotlist_address' => 'zod.public_hotlist_address',
        'hits' => 'zod.public_hits',
        'scans' => 'zod.public_scans',
    ];

    /**
     * Populated by materialized views off the mirrors above, so they must be
     * cleared alongside them or a re-run double-counts.
     *
     * @var list<string>
     */
    private const MV_TARGETS = ['zod.kit_last_scan', 'zod.scan_lag_5min', 'zod.hotlist_json_denormalized'];

    /**
     * @var array<string, array<string, string>>
     */
    private array $columnTypes = [];

    public function run(): void
    {
        foreach (self::MV_TARGETS as $table) {
            $this->clickhouse()->statement("TRUNCATE TABLE IF EXISTS {$table}");
        }

        foreach (self::MIRRORS as $pgTable => $chTable) {
            $this->replace($chTable, $this->selectMirrorRows($pgTable));
        }

        $this->replace('clarity.kits', $this->selectMirrorRows('kits'));
        $this->replace('clarity.hotlist', $this->selectHotlists());

        $scans = $this->selectScans();
        $this->replace('clarity.scans_cold', $scans);
        $this->replace('clarity.scans_hot', $this->withinHotWindow($scans, 'scanned_at'));

        $hits = $this->selectHits();
        $this->replace('clarity.hits_cold', $hits);
        $this->replace('clarity.hits_hot', $this->withinHotWindow($hits, 'hit_created_at'));

        $positions = $this->scoutPositions();
        $this->replace('clarity.sc_positions', $positions, ['kit_mapping = ?', [$positions[0]['kit_mapping'] ?? '']]);
    }

    /**
     * Coerce a Postgres value into what ClickHouse accepts for $type.
     */
    public static function coerce(string $type, mixed $value): mixed
    {
        $nullable = str_starts_with($type, 'Nullable(');
        $base = preg_replace('/^(Nullable|LowCardinality)\((.*)\)$/', '$2', $type);
        $base = preg_replace('/^(Nullable|LowCardinality)\((.*)\)$/', '$2', $base);

        if ($value === null) {
            return $nullable ? null : self::zeroValue($base);
        }

        return match (true) {
            str_starts_with($base, 'DateTime64') => Carbon::parse($value)->utc()->format('Y-m-d H:i:s.u'),
            str_starts_with($base, 'DateTime') => Carbon::parse($value)->utc()->format('Y-m-d H:i:s'),
            str_starts_with($base, 'Date') => Carbon::parse($value)->format('Y-m-d'),
            $base === 'Bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            $base === 'Point' => self::pointLiteral((string) $value),
            (bool) preg_match('/^U?Int/', $base) => (int) $value,
            (bool) preg_match('/^(Float|Decimal)/', $base) => (float) $value,
            is_array($value) || is_object($value) => json_encode($value),
            default => (string) $value,
        };
    }

    private static function zeroValue(string $base): mixed
    {
        return match (true) {
            str_starts_with($base, 'DateTime') => '1970-01-01 00:00:00',
            str_starts_with($base, 'Date') => '1970-01-01',
            $base === 'Point' => '(0, 0)',
            $base === 'UUID' => '00000000-0000-0000-0000-000000000000',
            (bool) preg_match('/^(U?Int|Float|Decimal|Bool)/', $base) => 0,
            default => '',
        };
    }

    /**
     * EWKT/WKT "SRID=4326;POINT(lng lat)" => ClickHouse Point literal "(lng, lat)".
     */
    private static function pointLiteral(string $wkt): string
    {
        if (preg_match('/^\(\s*[-\d.eE]+\s*,\s*[-\d.eE]+\s*\)$/', $wkt)) {
            return $wkt;
        }

        if (! preg_match('/POINT\s*\(\s*([-\d.eE]+)\s+([-\d.eE]+)\s*\)/i', $wkt, $matches)) {
            return '(0, 0)';
        }

        return "({$matches[1]}, {$matches[2]})";
    }

    /**
     * Truncate $table (or delete only the rows matching $deleteWhere) and insert $rows.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array{0: string, 1: list<mixed>}|null  $deleteWhere
     */
    private function replace(string $table, array $rows, ?array $deleteWhere = null): void
    {
        if ($deleteWhere === null) {
            $this->clickhouse()->statement("TRUNCATE TABLE IF EXISTS {$table}");
        } else {
            $this->clickhouse()->statement("ALTER TABLE {$table} DELETE WHERE {$deleteWhere[0]} SETTINGS mutations_sync = 1", $deleteWhere[1]);
        }

        $types = $this->columnTypes($table);
        $now = now()->utc()->format('Y-m-d H:i:s');

        $prepared = array_map(function (array $row) use ($types, $now): array {
            $out = [];

            foreach ($types as $column => $type) {
                if (array_key_exists($column, $row)) {
                    $out[$column] = self::coerce($type, $row[$column]);
                } elseif (in_array($column, ['_version', '_peerdb_version'], true)) {
                    $out[$column] = 1;
                } elseif ($column === '_peerdb_is_deleted') {
                    $out[$column] = 0;
                } elseif (str_starts_with($column, '_peerdb_') && str_ends_with($column, '_synced_at')) {
                    $out[$column] = self::coerce($type, $now);
                }
            }

            return $out;
        }, $rows);

        foreach (array_chunk($prepared, 500) as $chunk) {
            $this->clickhouse()->table($table)->insert($chunk);
        }

        $this->command->line(sprintf('  %-32s %d rows', $table, count($prepared)));
    }

    /**
     * @return array<string, string> column => ClickHouse type, insertable columns only
     */
    private function columnTypes(string $table): array
    {
        if (isset($this->columnTypes[$table])) {
            return $this->columnTypes[$table];
        }

        [$database, $name] = explode('.', $table, 2);

        $columns = $this->clickhouse()->select(
            "SELECT name, type FROM system.columns WHERE database = ? AND table = ? AND default_kind NOT IN ('MATERIALIZED', 'ALIAS') ORDER BY position",
            [$database, $name],
        );

        if ($columns === []) {
            throw new \RuntimeException("ClickHouse table {$table} does not exist; load the schema first.");
        }

        return $this->columnTypes[$table] = collect($columns)
            ->mapWithKeys(fn (array $column): array => [$column['name'] => $column['type']])
            ->all();
    }

    /**
     * SELECT * with geometry columns rendered as EWKT, matching CDC's string form.
     *
     * @return list<array<string, mixed>>
     */
    private function selectMirrorRows(string $pgTable): array
    {
        $columns = collect(DB::connection('zod')->select(
            "SELECT column_name, udt_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? ORDER BY ordinal_position",
            [$pgTable],
        ))->map(fn (object $column): string => $column->udt_name === 'geometry'
            ? "public.ST_AsEWKT({$column->column_name}) AS {$column->column_name}"
            : "\"{$column->column_name}\"");

        return $this->pgRows('SELECT '.$columns->implode(', ')." FROM public.{$pgTable}");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function selectHotlists(): array
    {
        return $this->pgRows(<<<'SQL'
            SELECT hl.*, hm.order_date, hm.repo_date, hm.close_date,
                   co.name AS client_org_name, lo.name AS lienholder_org_name
            FROM public.hotlist hl
            LEFT JOIN LATERAL (
                SELECT order_date, repo_date, close_date FROM public.hotlist_meta m
                WHERE m.hotlist_id = hl.id ORDER BY m.id DESC LIMIT 1
            ) hm ON true
            LEFT JOIN public.orgs co ON co.id = hl.client_org_id
            LEFT JOIN public.orgs lo ON lo.id = hl.lienholder_org_id
            SQL);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function selectScans(): array
    {
        return $this->pgRows(<<<'SQL'
            SELECT s.id, s.kit_id, s.ocr, s.us_state, s.camera_name, s.guid, s.scanned_at, s.created_at,
                   public.ST_X(s.geo) AS lng, public.ST_Y(s.geo) AS lat, public.ST_AsEWKT(s.geo) AS geo,
                   k.name AS kit_name, k.org_id
            FROM public.scans s
            JOIN public.kits k ON k.id = s.kit_id
            WHERE s.is_visible IS NOT FALSE
            SQL);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function selectHits(): array
    {
        return $this->pgRows(<<<'SQL'
            SELECT h.id, h.hotlist_id, h.scan_id, h.hit_type, h.created_at AS hit_created_at,
                   s.ocr, s.scanned_at, s.camera_name, s.us_state AS scan_us_state, s.guid AS scan_guid,
                   k.id AS kit_id, k.name AS kit_name, k.org_id AS kit_org_id,
                   hl.us_state AS hotlist_us_state, hl.plate AS hotlist_plate, hl.status AS hotlist_status,
                   hl.vin AS hotlist_vin, hl.org_id AS hotlist_org_id, hl.remote_order_id, hl.integration_id,
                   hl.client_org_id, hl.lienholder_org_id, hl.vehicle_year, hl.vehicle_make, hl.vehicle_model,
                   hm.order_date, hm.repo_date, hm.close_date,
                   co.name AS client_org_name, lo.name AS lienholder_org_name,
                   public.ST_Y(s.geo) AS lat, public.ST_X(s.geo) AS lng
            FROM public.hits h
            JOIN public.scans s ON s.id = h.scan_id
            JOIN public.kits k ON k.id = COALESCE(h.kit_id, s.kit_id)
            JOIN public.hotlist hl ON hl.id = h.hotlist_id
            LEFT JOIN LATERAL (
                SELECT order_date, repo_date, close_date FROM public.hotlist_meta m
                WHERE m.hotlist_id = hl.id ORDER BY m.id DESC LIMIT 1
            ) hm ON true
            LEFT JOIN public.orgs co ON co.id = hl.client_org_id
            LEFT JOIN public.orgs lo ON lo.id = hl.lienholder_org_id
            SQL);
    }

    /**
     * Scout GPS has no Postgres source: synthesize the last two hours of
     * minute-by-minute fixes for the Scout user's kit, around its latest scan.
     *
     * @return list<array<string, mixed>>
     */
    private function scoutPositions(): array
    {
        $userId = User::query()->where('email', CloudSmokeSeeder::PRIMARY_EMAIL)->value('id');
        $kitId = KitSettings::query()->where('user_id', $userId)->value('kit_id');
        $kit = $kitId ? Kit::query()->find($kitId) : null;

        if (! $kit) {
            return [];
        }

        $origin = DB::connection('zod')->selectOne(
            'SELECT public.ST_X(geo) AS lng, public.ST_Y(geo) AS lat FROM public.scans WHERE kit_id = ? AND geo IS NOT NULL ORDER BY scanned_at DESC LIMIT 1',
            [$kit->id],
        );

        $lng = (float) ($origin->lng ?? -86.1581);
        $lat = (float) ($origin->lat ?? 39.7684);
        $start = now()->utc()->subMinutes(120)->startOfMinute();

        return collect(range(0, 120))->map(fn (int $minute): array => [
            'id' => $minute + 1,
            'kit_mapping' => (string) $kit->kit_mapping,
            'gl' => (string) $kit->kit_mapping,
            'timestamp_utc' => $start->copy()->addMinutes($minute),
            'timestamp_local' => $start->copy()->addMinutes($minute),
            'geo' => sprintf('(%F, %F)', $lng + $minute * 0.0004, $lat + sin($minute / 10) * 0.002),
            'speed' => 25.0,
            'heading' => 90.0,
        ])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withinHotWindow(array $rows, string $column): array
    {
        $cutoff = now()->subDays(self::HOT_WINDOW_DAYS);

        return array_values(array_filter($rows, fn (array $row): bool => Carbon::parse($row[$column])->greaterThanOrEqualTo($cutoff)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pgRows(string $sql): array
    {
        return array_map(fn (object $row): array => (array) $row, DB::connection('zod')->select($sql));
    }

    private function clickhouse(): Connection
    {
        return DB::connection(self::CONNECTION);
    }
}
