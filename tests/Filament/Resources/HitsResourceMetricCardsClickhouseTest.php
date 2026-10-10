<?php

declare(strict_types=1);

namespace Tests\Filament\Resources;

use App\Enums\HitTypeEnum;
use App\Enums\HotlistStatusEnum;
use App\Filament\Resources\HitsResource\Pages\ListHits;
use App\Models\Kit;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\Filament\Concerns\HasHitMetricCardsTest;
use Tests\ResourceTestCase;

/**
 * The ClickHouse half of the DEV-2546 metric cards. HitsResourceTest covers the Postgres
 * branch; this covers the one that actually serves production — every org with the
 * `clickhouse:hits` flag, and every admin impersonator.
 *
 * It is a separate class because the branch emits genuinely different SQL (`uniqExact(...)`
 * over the flat hits table instead of `count(distinct …)` / `count(*)` over the six-way join)
 * and returns a different row shape (the HTTP client hands back associative arrays where PDO
 * hands back stdClass). Both of those fail into an em dash rather than an error, so without an
 * executing test they would break silently.
 *
 * Skips locally unless CLICKHOUSE_DRIVER=clickhouse; CI always runs it. The driver branch
 * ITSELF is deliberately not pinned here — a test that skips on `CLICKHOUSE_DRIVER=pgsql`
 * cannot cover the bug that only appears on `CLICKHOUSE_DRIVER=pgsql`. That lives in
 * {@see HasHitMetricCardsTest}, which never skips.
 */
#[Group('clickhouse')]
#[Group('hits')]
class HitsResourceMetricCardsClickhouseTest extends ResourceTestCase
{
    use RequiresClickhouse;

    protected mixed $userPermissions = PermissionEnum::ViewAny_Hit->value;

    private int $hitSeq = 1;

    private Kit $kit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupRequiresClickhouse();

        $this->kit = Kit::factory()->forOrg($this->user->org)->create();

        // shouldUseClickhouse() without depending on Pennant state.
        session()->put('admin_impersonator', $this->user->id);
    }

    #[Group('resources')]
    public function test_cards_count_the_filtered_clickhouse_dataset(): void
    {
        // Two Live hits on one plate + one on another: unique 2, total 3.
        $this->seedHit(['ocr' => 'AAA1111', 'hit_type' => HitTypeEnum::Live->value]);
        $this->seedHit(['ocr' => 'AAA1111', 'hit_type' => HitTypeEnum::Live->value]);
        $this->seedHit(['ocr' => 'BBB2222', 'hit_type' => HitTypeEnum::Live->value]);
        $this->seedHit(['ocr' => 'CCC3333', 'hit_type' => HitTypeEnum::Staging_Historical->value]);

        $cards = $this->metricCards($this->hitsPage());

        $this->assertSame(3, $cards['live-hits']['total'], 'raw hit count');
        $this->assertSame(2, $cards['live-hits']['unique'], 'deduped by plate');
        $this->assertSame(1, $cards['staging-historical-hits']['total']);
        $this->assertSame(0, $cards['historical-hits']['total']);
        $this->assertSame(0, $cards['staging-live-hits']['total']);
    }

    /**
     * Regression pin for the review-round-2 High finding: the raw total must be
     * `uniqExact(id)`, never a bare `count()`.
     *
     * `hits_hot`/`hits_cold` are ReplacingMergeTree whose sort key contains the MUTABLE
     * `hit_created_at`, so two rows for one hit `id` with different `hit_created_at` never
     * collapse — not even under `FINAL`. A bare `count()` therefore reads them as two hits;
     * the repo forbids it on this table in four places and measured the error at up to ~30%
     * in production. This test inserts exactly that shape, so a revert to `count()` fails here
     * instead of silently inflating the number under every user's filtered view.
     */
    #[Group('resources')]
    public function test_raw_total_does_not_double_count_an_unmerged_duplicate_row(): void
    {
        // One real hit, stored twice with a moved hit_created_at — the residue shape FINAL
        // cannot collapse. A bare count() reads 2; uniqExact(id) must read 1.
        $this->seedHit([
            'id' => 9001,
            'ocr' => 'DUP0001',
            'hit_type' => HitTypeEnum::Live->value,
            'hit_created_at' => now()->subHours(3)->toDateTimeString(),
        ]);
        $this->seedHit([
            'id' => 9001,
            'ocr' => 'DUP0001',
            'hit_type' => HitTypeEnum::Live->value,
            'hit_created_at' => now()->subHours(2)->toDateTimeString(),
        ]);

        $cards = $this->metricCards($this->hitsPage());

        $this->assertSame(1, $cards['live-hits']['total'], 'raw total must dedupe by hit id, not count rows');
        $this->assertSame(1, $cards['live-hits']['unique'], 'one plate');
    }

    /**
     * DEV-2614: the cards inherit the list builder's `FINAL` on `hits_hot`, so a stale status
     * version (same sort key, older `_version`) is not counted under the default Open filter.
     */
    #[Group('resources')]
    public function test_cards_ignore_a_stale_status_version_on_the_hot_table(): void
    {
        $id = \random_int(100_000_000, 900_000_000);
        $createdAt = now()->subHours(2)->toDateTimeString();

        $this->seedHit(['id' => $id, 'ocr' => 'FIN0001', 'hit_created_at' => $createdAt, 'hotlist_status' => HotlistStatusEnum::Open->value, '_version' => 1]);
        $this->seedHit(['id' => $id, 'ocr' => 'FIN0001', 'hit_created_at' => $createdAt, 'hotlist_status' => HotlistStatusEnum::Repossessed->value, '_version' => 2]);

        $cards = $this->metricCards($this->hitsPage());

        $this->assertSame(0, $cards['live-hits']['total'], 'the Repossessed version replaces the Open one under FINAL');
    }

    #[Group('resources')]
    public function test_cards_react_to_a_filter_change_on_the_clickhouse_branch(): void
    {
        $this->seedHit(['ocr' => 'AAA1111', 'hit_type' => HitTypeEnum::Live->value]);
        $this->seedHit(['ocr' => 'ZZZ9999', 'hit_type' => HitTypeEnum::Staging_Live->value]);

        $component = $this->hitsPage();

        $this->assertSame(1, $this->metricCards($component)['live-hits']['total']);
        $this->assertSame(1, $this->metricCards($component)['staging-live-hits']['total']);

        $component->set('tableFilters.filters.hit_type', HitTypeEnum::Staging_Live->value);

        $after = $this->metricCards($component);
        $this->assertSame(0, $after['live-hits']['total']);
        $this->assertSame(1, $after['staging-live-hits']['total']);
    }

    #[Group('resources')]
    public function test_cards_exclude_hits_from_kits_the_user_cannot_see(): void
    {
        $this->seedHit(['ocr' => 'AAA1111', 'hit_type' => HitTypeEnum::Live->value]);
        $this->seedHit(['ocr' => 'BBB2222', 'hit_type' => HitTypeEnum::Live->value, 'kit_id' => $this->kit->id + 9999]);

        $this->assertSame(1, $this->metricCards($this->hitsPage())['live-hits']['total']);
    }

    /**
     * AC-4 gap, accepted (RN5 operator decision): a hit type none of the four cards names is
     * still listed by the table (both branches exclude only OverWatch, and the Type filter
     * offers F and P), and NO card counts it — the card set stays fixed at four (AC-1) rather
     * than growing a fifth "Other Hits" card. This pins the acceptance directly on the branch
     * that serves production.
     */
    #[Group('resources')]
    public function test_a_hit_type_no_card_names_is_accepted_as_uncounted(): void
    {
        $this->seedHit(['ocr' => 'AAA1111', 'hit_type' => HitTypeEnum::Live->value]);
        $this->seedHit(['ocr' => 'PPP7777', 'hit_type' => HitTypeEnum::Probable_Match->value]);

        $cards = $this->metricCards($this->hitsPage());

        $this->assertCount(4, $cards);
        $this->assertArrayNotHasKey('other-hits', $cards);
        $this->assertSame(1, $cards['live-hits']['total']);
        $this->assertSame(0, $cards['historical-hits']['total']);
        $this->assertSame(0, $cards['staging-live-hits']['total']);
        $this->assertSame(0, $cards['staging-historical-hits']['total'], 'no card counts the Probable Match');
    }

    private function hitsPage(): Testable
    {
        return Livewire::test(ListHits::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay());
    }

    /**
     * @return array<string, array{key: string, label: string, color: string, unique: int|null, total: int|null}>
     */
    private function metricCards(Testable $component): array
    {
        return \collect($component->instance()->getWidgetData()['hitMetricCards'])
            ->keyBy('key')
            ->all();
    }

    private function seedHit(array $overrides = []): void
    {
        // hits_hot, not hits_cold: the page routes windows inside 90 days to the hot table,
        // and hits_hot drops rows older than 90 days at INSERT — so anchor to now().
        // Use the connection's configured database (CI: `clarity`; worktree QA: `clarity_<wt>_test`).
        // A hard-coded `clarity.hits_hot` qualifier breaks when clickhouse-qa renames the DB.
        DB::connection('clarity_clickhouse')->table('hits_hot')->insert(\array_merge([
            'id' => $this->hitSeq++,
            'hotlist_id' => 1,
            'scan_id' => $this->hitSeq,
            'hit_type' => HitTypeEnum::Live->value,
            'hit_created_at' => now()->subHours(2)->toDateTimeString(),
            'ocr' => 'ABC1234',
            'scanned_at' => now()->subHours(2)->toDateTimeString(),
            'camera_name' => 'CAM-1',
            'scan_us_state' => 'TX',
            'kit_id' => $this->kit->id,
            'kit_name' => $this->kit->name,
            'kit_org_id' => $this->user->org_id,
            'hotlist_org_id' => $this->user->org_id,
            'hotlist_us_state' => 'TX',
            'hotlist_plate' => 'ABC1234',
            'hotlist_status' => 1,
            // Nullable(Int32): notNicb() is `integration_id != NICB`, and NULL != 5 is NULL,
            // which would silently drop every seeded row.
            'integration_id' => 1,
            '_peerdb_synced_at' => now()->toDateTimeString(),
            '_peerdb_hotlist_synced_at' => now()->toDateTimeString(),
            '_version' => 1,
            '_peerdb_is_deleted' => 0,
        ], $overrides));
    }
}
