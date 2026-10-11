<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse\Queries;

use App\Clickhouse\Queries\OrgKitLastScanQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

class OrgKitLastScanQueryTest extends TestCase
{
    use RequiresClickhouse;

    private const ORG_ID = 9001;

    private const OTHER_ORG_ID = 9002;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Group('clickhouse')]
    public function test_empty_kit_ids_returns_an_empty_array(): void
    {
        $this->assertSame([], OrgKitLastScanQuery::handle(['org_id' => self::ORG_ID, 'kit_ids' => []]));
    }

    #[Group('clickhouse')]
    public function test_missing_kit_ids_returns_an_empty_array(): void
    {
        $this->assertSame([], OrgKitLastScanQuery::handle(['org_id' => self::ORG_ID]));
    }

    /**
     * A missing org_id would otherwise become 0, match nothing, and render every row
     * "Never" — a caller wiring bug that looks exactly like a fleet with no scans.
     */
    #[Group('clickhouse')]
    public function test_a_missing_org_id_throws_rather_than_matching_nothing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OrgKitLastScanQuery::handle(['kit_ids' => [4101]]);
    }

    /**
     * `zod.kit_last_scan` is a ReplacingMergeTree that is NOT reliably merged, so a
     * read must collapse duplicate (org_id, kit_id) rows itself rather than trust
     * merge state or FINAL. Reverting the query to a plain select fails this test.
     */
    #[Group('clickhouse')]
    public function test_duplicate_rows_for_one_kit_collapse_to_the_newest_last_scan(): void
    {
        $newer = now()->startOfSecond();
        $older = $newer->copy()->subHour();

        $this->insertKitLastScan(self::ORG_ID, 4101, $older);
        $this->insertKitLastScan(self::ORG_ID, 4101, $newer);

        $result = OrgKitLastScanQuery::handle(['org_id' => self::ORG_ID, 'kit_ids' => [4101]]);

        $this->assertCount(1, $result);
        // Both sides pinned to UTC: the column is UTC, so an APP_TIMEZONE-expressed
        // expectation or a tz-less parse would compare two different instants.
        $this->assertSame(
            $newer->copy()->utc()->format('Y-m-d H:i:s'),
            Carbon::parse($result[4101], 'UTC')->format('Y-m-d H:i:s')
        );
    }

    #[Group('clickhouse')]
    public function test_a_kit_with_no_row_is_absent_rather_than_null_or_zero(): void
    {
        $this->insertKitLastScan(self::ORG_ID, 4201, now());

        $result = OrgKitLastScanQuery::handle([
            'org_id' => self::ORG_ID,
            'kit_ids' => [4201, 4202],
        ]);

        $this->assertArrayHasKey(4201, $result);
        $this->assertArrayNotHasKey(4202, $result);
    }

    /**
     * The table's sort key is (org_id, kit_id), so a kit that changed orgs keeps a
     * stale row under its former org. The org_id predicate must exclude that row
     * even when the kit_id is explicitly requested — tenancy is the caller's.
     */
    #[Group('clickhouse')]
    public function test_a_row_under_a_different_org_is_never_returned(): void
    {
        $this->insertKitLastScan(self::OTHER_ORG_ID, 4301, now());

        $result = OrgKitLastScanQuery::handle(['org_id' => self::ORG_ID, 'kit_ids' => [4301]]);

        $this->assertArrayNotHasKey(4301, $result);
    }

    /**
     * `last_scan` is a UTC column, so the instant is converted to UTC here rather
     * than at each call site — a naive `now()`-derived string would be expressed in
     * APP_TIMEZONE and silently shift every fixture.
     */
    private function insertKitLastScan(int $orgId, int $kitId, Carbon $lastScan): void
    {
        DB::connection('public_clickhouse')->table('kit_last_scan')->insert([
            'org_id' => $orgId,
            'org_name' => 'Org '.$orgId,
            'kit_id' => $kitId,
            'kit_name' => 'Kit '.$kitId,
            'last_scan' => $lastScan->copy()->utc()->format('Y-m-d H:i:s.u'),
        ]);
    }
}
