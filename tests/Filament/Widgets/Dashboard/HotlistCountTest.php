<?php

namespace Tests\Filament\Widgets\Dashboard;

use App\Filament\Widgets\Dashboard\CompanyMetricsOverview;
use App\Filament\Widgets\Dashboard\HotlistCount;
use App\Filament\Widgets\Dashboard\KitUsers;
use Illuminate\View\ComponentAttributeBag;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Note: The HotlistCount widget uses ClickHouse-specific aggregate functions
 * (countDistinctIf, countIf) in its selectRaw() that cannot be tested against
 * the PostgreSQL fallback. The fetchHotlistCounts() query requires ClickHouse.
 * These tests verify rendering and permission behavior using cache pre-seeding.
 */
class HotlistCountTest extends TestCase
{
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('hotlist')]
    public function test_can_render(): void
    {
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        // Bypass staging org count
        cache()->put('staging-org-hotlist-count:', 0);

        // Pre-seed combined hotlist cache key to avoid ClickHouse queries in CI
        cache()->put('hotlist-count:combined:org='.$user->org_id, [
            'total_direct' => 0,
            'direct_with_plates' => 0,
            'missing_plates' => 0,
            'total_staged' => 0,
        ]);

        // DEV-2460: Active Kit Users moved into this row and sources its two numbers from
        // the KitUsers widget's EXISTING cache keys. Seeding those same keys is what proves
        // the reuse -- if the implementation ever re-queries under a different key, these
        // seeds stop covering it and the test hits ClickHouse and fails.
        cache()->put('kit-status:active-ids:org='.$user->org_id, []);
        cache()->put('kit-status:total:org='.$user->org_id, 0);

        Livewire::test(HotlistCount::class)
            ->assertSuccessful()
            ->assertSeeText('Total Open Assignments')
            ->assertSeeText('Open Assignments with Plates')
            ->assertSeeText('Open Assignments Missing Plates')
            ->assertSeeText('Missing Plates Percentage')
            ->assertSeeText('Active Kit Users')
            // "kits", not "camera kits": cameraKitsCount() has no kit_type filter, so the
            // label must not claim one. See HotlistCount::getStats().
            ->assertDontSeeText('camera kits')
            ->assertSeeText('Staging');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('hotlist')]
    public function test_the_header_row_columns_match_the_measured_breakpoints(): void
    {
        // Pinned because this has been wrong four times in DEV-2460, in both directions.
        // What it is NOT: a claim that six stats sit on one row from lg upward. An earlier
        // version asserted exactly that and called lg:3 "a regression at the most common
        // desktop width"; measurement showed the opposite -- at 1024, the bulk of lg, six
        // gives a 92px card and breaks four text nodes. md and lg are both 3 because the
        // sidebar hides below lg, making 900px roomier than 1024px. Do not change these
        // without re-measuring; see docs/investigations/DEV-2460/layout-findings.md.
        $widget = new HotlistCount;

        $method = new ReflectionMethod($widget, 'getColumns');
        $method->setAccessible(true);

        $this->assertSame([
            'default' => 1,
            'sm' => 2,
            'md' => 3,
            'lg' => 3,
            'xl' => 6,
        ], $method->invoke($widget), 'the mockup single row is xl-only; lg/md must stay readable');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('hotlist')]
    public function test_both_dashboard_widgets_span_the_full_page_width(): void
    {
        // Runs each widget's span through Filament's OWN gridColumn macro and asserts what it
        // emits, not how the property is spelled. Discriminates every failure mode:
        //   'full'                        -> "--col-span-lg: 1 / -1"        (the half-width bug)
        //   ['default' => 'full']         -> "--col-span-default: 1 / -1"   (correct)
        //   ['default'=>'full','xl'=>1]   -> adds "--col-span-xl: span 1"   (breaks at xl)
        // An exact match is therefore both necessary and sufficient. Covers BOTH widgets, so
        // reverting either property fails here.
        foreach ([HotlistCount::class, CompanyMetricsOverview::class] as $widget) {
            $style = (new ComponentAttributeBag)
                ->gridColumn((new $widget)->getColumnSpan())
                ->get('style');

            $this->assertSame(
                '--col-span-default: 1 / -1;',
                $style,
                $widget.' must span the full page width at every breakpoint'
            );
        }
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('hotlist')]
    public function test_cannot_render_without_permission(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        Livewire::test(HotlistCount::class)
            ->assertForbidden()
            ->assertDontSeeText('Total Open Assignments')
            ->assertDontSeeText('Open Assignments with Plates')
            ->assertDontSeeText('Open Assignments Missing Plates')
            ->assertDontSeeText('Missing Plates Percentage')
            ->assertDontSeeText('Active Kit Users')
            ->assertDontSeeText('Staging');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('hotlist')]
    public function test_can_render_stats(): void
    {
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        // Bypass staging org count
        cache()->put('staging-org-hotlist-count:', 0);

        $directCount = fake()->numberBetween(50, 100);
        $directWithPlatesCount = fake()->numberBetween(30, 50);
        $missingCount = fake()->numberBetween(1, 20);
        $stagingCount = fake()->numberBetween(1, 100);

        cache()->put('hotlist-count:combined:org='.$user->org_id, [
            'total_direct' => $directCount,
            'direct_with_plates' => $directWithPlatesCount,
            'missing_plates' => $missingCount,
            'total_staged' => $stagingCount,
        ]);

        $activeKitIds = \range(1, fake()->numberBetween(2, 9));
        // >=1000 on purpose: the old 10-400 range never exercised number_format()'s
        // thousands separator in the description.
        $cameraKits = fake()->numberBetween(1200, 9800);

        cache()->put('kit-status:active-ids:org='.$user->org_id, $activeKitIds);
        cache()->put('kit-status:total:org='.$user->org_id, $cameraKits);

        $rendered = Livewire::test(HotlistCount::class, ['date' => 'today'])
            ->assertSuccessful()
            ->assertSee('data-stat="Direct: '.$directCount.'"', false)
            ->assertSee('data-stat="Direct with Plates: '.$directWithPlatesCount.'"', false)
            ->assertSee('data-stat="Missing Plates: '.$missingCount.'"', false)
            ->assertSee('data-stat="Active Kit Users: '.\count($activeKitIds).'"', false)
            ->assertSee(\number_format($cameraKits).' kits')
            ->assertSee('data-stat="Staging: '.$stagingCount.'"', false);

        // DEV-2460: position 5 of 6 -- AFTER Missing Plates Percentage, BEFORE Staging.
        // Read off the mockup (DEV-2409 attachment 24421); appending it would be wrong.
        $html = $rendered->html();

        // strpos() returns false on a miss and PHPUnit juggles false to 0, so a MISSING
        // needle would make the ordering assertions below pass vacuously. Pin both first.
        $this->assertNotFalse(\strpos($html, 'data-stat="Active Kit Users: '));
        $this->assertNotFalse(\strpos($html, 'data-stat="Staging: '));
        $this->assertNotFalse(\strpos($html, 'Missing Plates Percentage'));

        $this->assertLessThan(
            \strpos($html, 'data-stat="Staging: '),
            \strpos($html, 'data-stat="Active Kit Users: '),
            'Active Kit Users must render BEFORE Staging'
        );
        $this->assertGreaterThan(
            \strpos($html, 'Missing Plates Percentage'),
            \strpos($html, 'data-stat="Active Kit Users: '),
            'Active Kit Users must render AFTER Missing Plates Percentage'
        );
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('hotlist')]
    public function test_active_kit_users_reuses_the_kit_users_widget_cache_keys(): void
    {
        // DEV-2460 AC: per-render ClickHouse round-trip count must not regress. The two
        // reads behind Active Kit Users already ran when KitUsers was a grid card, so the
        // move is only net-neutral if HotlistCount reads THOSE keys rather than issuing its
        // own queries. `.env.testing` points ClickHouse at the pgsql bridge with no
        // clarity.* tables, so a stray real query would throw -- rendering successfully off
        // nothing but these seeds is the proof.
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        cache()->put('staging-org-hotlist-count:', 0);
        cache()->put('hotlist-count:combined:org='.$user->org_id, [
            'total_direct' => 0,
            'direct_with_plates' => 0,
            'missing_plates' => 0,
            'total_staged' => 0,
        ]);
        cache()->put('kit-status:active-ids:org='.$user->org_id, [7, 8, 9]);
        cache()->put('kit-status:total:org='.$user->org_id, 42);

        $html = Livewire::test(HotlistCount::class)
            ->assertSuccessful()
            ->assertSee('data-stat="Active Kit Users: 3"', false)
            ->html();

        // Pinned as the rendered span, not a bare assertSee('42 kits') -- that substring
        // also matches "142 kits" / "1,042 kits". Same reason as the two fail-soft tests below.
        $this->assertMatchesRegularExpression('/>\s*42 kits\s*</', $html);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('hotlist')]
    public function test_a_failing_kit_read_degrades_that_stat_without_taking_the_row_down(): void
    {
        // R3. Every other number in this widget is deliberately fail-soft
        // (fetchHotlistCounts() catches Throwable), while KitUsers' accessors document the
        // OPPOSITE policy on purpose. Composing them means this wrap is what stops a
        // ClickHouse blip taking out the four hotlist numbers too.
        //
        // Deterministic by construction. An earlier version relied on an unseeded cache key
        // reaching an unreachable ClickHouse -- true locally, FALSE in CI, where the
        // workflow runs a live clickhouse-server and loads clarity.kits, so the read
        // returned 0 without throwing and the assertion passed vacuously (round 4). This
        // injects a source whose reads throw, so it behaves identically in both.
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        cache()->put('staging-org-hotlist-count:', 0);
        cache()->put('hotlist-count:combined:org='.$user->org_id, [
            'total_direct' => 77,
            'direct_with_plates' => 70,
            'missing_plates' => 7,
            'total_staged' => 5,
        ]);

        $html = Livewire::test(HotlistCountWithFailingKitSource::class)
            ->assertSuccessful()
            // the hotlist numbers survive -- the row did NOT come down
            ->assertSee('data-stat="Direct: 77"', false)
            ->assertSee('data-stat="Missing Plates: 7"', false)
            // the failing legs degrade to 0 for this render only
            ->assertSee('data-stat="Active Kit Users: 0"', false)
            ->html();

        // A bare assertSee('0 kits') would also match "10 kits" / "1,000 kits", so pin the
        // rendered span exactly. The description sits inside <span>{{ $description }}</span>,
        // hence the surrounding whitespace in the pattern.
        $this->assertMatchesRegularExpression(
            '/>\s*0 kits\s*</',
            $html,
            'the failing cameras leg must degrade to exactly 0, not merely to a number containing 0'
        );
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('hotlist')]
    public function test_one_failing_kit_leg_does_not_zero_the_other(): void
    {
        // The partial-failure half of R3: wrapping both reads together used to throw away a
        // value that had already been computed when only the other leg failed.
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        cache()->put('staging-org-hotlist-count:', 0);
        cache()->put('hotlist-count:combined:org='.$user->org_id, [
            'total_direct' => 1,
            'direct_with_plates' => 1,
            'missing_plates' => 0,
            'total_staged' => 0,
        ]);

        $html = Livewire::test(HotlistCountWithHalfFailingKitSource::class)
            ->assertSuccessful()
            // active succeeded and keeps its real value...
            ->assertSee('data-stat="Active Kit Users: 5"', false)
            // ...while only the failing leg degrades, and to exactly 0
            ->html();

        $this->assertMatchesRegularExpression(
            '/>\s*0 kits\s*</',
            $html,
            'the failing cameras leg must degrade to exactly 0 while the other leg survives'
        );
    }
}

/** A KitUsers whose two reads both throw, standing in for a ClickHouse outage. */
class FailingKitUsers extends KitUsers
{
    public function activeKitUsersCount(): int
    {
        throw new RuntimeException('simulated ClickHouse failure');
    }

    public function cameraKitsCount(): int
    {
        throw new RuntimeException('simulated ClickHouse failure');
    }
}

/** A KitUsers where only the camera-kit read fails. */
class HalfFailingKitUsers extends KitUsers
{
    public function activeKitUsersCount(): int
    {
        return 5;
    }

    public function cameraKitsCount(): int
    {
        throw new RuntimeException('simulated ClickHouse failure');
    }
}

class HotlistCountWithFailingKitSource extends HotlistCount
{
    protected function kitUsersSource(): KitUsers
    {
        return new FailingKitUsers;
    }
}

class HotlistCountWithHalfFailingKitSource extends HotlistCount
{
    protected function kitUsersSource(): KitUsers
    {
        return new HalfFailingKitUsers;
    }
}
