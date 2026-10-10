<?php

namespace Tests\Filament\Resources;

use App\Filament\Resources\GlKitsResource;
use App\Filament\Resources\GlKitsResource\Pages\ListGlKits;
use App\Models\Kit;
use App\Models\Org;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\ResourceTestCase;

/**
 * The GL Kits guarantees that need NO ClickHouse: authorization and tenant scoping.
 * They live apart from {@see GlKitsResourceTest} deliberately — that class uses
 * RequiresClickhouse, which markTestSkipped()s the whole class unless
 * CLICKHOUSE_DRIVER is `clickhouse`. That is the documented local default, so gating
 * these behind it would mean deleting canAccess() or the org_id scope still produced
 * a fully green run for most developers, and would do the same in CI if the
 * ClickHouse service failed to start.
 *
 * Since DEV-2486 the page's ROWS come from ClickHouse (`zod.aggregate_kit_camera_name`
 * has no PostgreSQL equivalent), so these tests can no longer assert which rows
 * render — that moved to {@see GlKitsResourceTest}. What they assert instead is the
 * PostgreSQL query that feeds ClickHouse: it is the only tenancy boundary on this
 * page, because the ClickHouse table has no org_id of its own. Deleting the org_id
 * scope, the kit_type filter, the soft-delete exclusion, or canAccess() still fails
 * here with ClickHouse completely absent.
 */
class GlKitsResourceAccessTest extends ResourceTestCase
{
    protected mixed $userRoles = RoleEnum::ADMIN->value;

    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_can_render_index_for_admin(): void
    {
        Livewire::test(ListGlKits::class)->assertSuccessful();
    }

    /**
     * `canAccess()` is the ONLY gate on this page and every other test in this file
     * runs as an ADMIN, so without this the gate could be deleted or the role string
     * fat-fingered and the suite would stay green. Matches the convention in
     * ScanReportResourceTest / AffiliateStatsResourceTest / ScoutUsersResourceTest.
     */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_cannot_render_without_the_admin_role(): void
    {
        $this->actingAs($this->createUser(role: []));

        Livewire::test(ListGlKits::class)->assertForbidden();
    }

    /**
     * The kit list is what scopes every ClickHouse read on this page, so another
     * org's kit must never enter it. This is the assertion that used to be made
     * against rendered rows.
     */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_the_kit_query_excludes_another_orgs_kits(): void
    {
        $mine = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);

        $otherOrg = Org::factory()->create();
        $theirs = Kit::factory()->forOrg($otherOrg)->create(['kit_type' => 1]);

        $ids = GlKitsResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_the_kit_query_includes_only_gl_kit_type_kits(): void
    {
        $gl = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);
        $matrix = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 2]);

        $ids = GlKitsResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($gl->id, $ids);
        $this->assertNotContains($matrix->id, $ids);
    }

    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_the_kit_query_excludes_soft_deleted_kits(): void
    {
        $active = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);
        $deleted = Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);

        DB::connection('zod')->table('kit_metadata')->insert([
            'kit_id' => $deleted->id,
            'deleted_at' => now(),
        ]);

        $ids = GlKitsResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($deleted->id, $ids);
    }

    /**
     * The page must RENDER rather than error when the ClickHouse reads yield nothing —
     * which is what a GL-discovery failure looks like (DEV-2486's accepted trade-off:
     * an outage empties the table rather than degrading each row to "Unknown").
     *
     * Deliberately asserts only that it renders, not a row count. This class does not
     * use RequiresClickhouse, so the environment differs: locally CLICKHOUSE_DRIVER is
     * `pgsql` and the reads fail, while CI runs a live ClickHouse. A row-count assertion
     * would therefore pin a different property per environment, and — because
     * `zod.aggregate_kit_camera_name` is truncated only by RequiresClickhouse, which this
     * class does not use, while RefreshDatabase resets the kits id sequence every run —
     * a stale local row could collide with a reissued kit id and fail it spuriously.
     * The discovery-failure behaviour itself is pinned by
     * {@see GlKitsResourceTest::test_a_gl_discovery_failure_renders_zero_rows_and_is_reported}.
     */
    #[Group('resources')]
    #[Group('gl-kits')]
    public function test_the_page_renders_when_the_clickhouse_reads_yield_nothing(): void
    {
        Kit::factory()->forOrg($this->user->org)->create(['kit_type' => 1]);

        Livewire::test(ListGlKits::class)->assertSuccessful();
    }
}
