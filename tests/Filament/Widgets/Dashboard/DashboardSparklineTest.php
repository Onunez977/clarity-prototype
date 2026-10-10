<?php

namespace Tests\Filament\Widgets\Dashboard;

use App\Filament\Widgets\Dashboard\DashboardSparkline;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class DashboardSparklineTest extends TestCase
{
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_paths_draw_a_sloped_line_for_changing_values(): void
    {
        $paths = DashboardSparkline::paths([0, 10, 5], width: 100, height: 50);

        $this->assertSame('0 0 100 50', $paths['viewBox']);
        $this->assertMatchesRegularExpression('/^M 0 \d/', $paths['line']);
        $this->assertStringContainsString(' L 50 ', $paths['line']);
        $this->assertStringContainsString(' L 100 ', $paths['line']);

        preg_match_all('/[\d.]+ ([\d.]+)/', $paths['line'], $matches);
        $ys = array_map('floatval', $matches[1]);
        $this->assertCount(3, $ys);
        $this->assertGreaterThan($ys[1], $ys[0], 'the 0-value sits below the peak');
        $this->assertGreaterThan($ys[1], $ys[2]);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_paths_keep_a_visible_flat_line_when_all_values_are_zero(): void
    {
        $paths = DashboardSparkline::paths([0, 0, 0, 0], width: 100, height: 50);

        preg_match_all('/[\d.]+ ([\d.]+)/', $paths['line'], $matches);
        $ys = array_map('floatval', $matches[1]);

        $this->assertNotEmpty($ys);
        $this->assertTrue(
            max($ys) < 50 && min($ys) > 0,
            'a zero series must not clip to the SVG edge or the line disappears'
        );
        $this->assertGreaterThan(
            min($ys),
            max($ys),
            'a flat series must still have a non-zero path height so Playwright toBeVisible() passes'
        );
        $this->assertStringEndsWith(' Z', $paths['area']);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_published_stat_view_renders_an_svg_instead_of_a_chart_js_canvas(): void
    {
        $view = file_get_contents(resource_path('views/vendor/filament-widgets/stats-overview-widget/stat.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('DashboardSparkline', $view);
        $this->assertStringContainsString('<svg', $view);
        $this->assertStringNotContainsString('statsOverviewStatChart', $view);
        $this->assertStringNotContainsString('<canvas', $view);
        $this->assertStringNotContainsString('$chartDataChecksum', $view);
        // The SVG is the point of this view; its LAYOUT is not inlined here. The card
        // used to hardcode `flex flex-col` + `margin-top: auto` + `height: 8rem
        // !important`, and inline styles beat the stylesheet -- so the card stayed ~240px
        // tall no matter what theme.css said, and a CSS-only fix silently did nothing in
        // the browser. Height now comes from theme.css, which sizes the SVG to Filament's
        // own 24px strip inside the card's bottom padding (Oscar, DEV-2324 follow-up).
        $this->assertStringNotContainsString('8rem', $view);
        $this->assertStringNotContainsString('margin-top: auto', $view);
        $this->assertStringNotContainsString('flex flex-col', $view);
        $this->assertStringNotContainsString('inset: auto', $view);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_paths_keep_peaks_below_the_stat_description(): void
    {
        $height = 80;
        $paths = DashboardSparkline::paths([0, 100, 0], width: 100, height: $height);

        preg_match_all('/[\d.]+ ([\d.]+)/', $paths['line'], $matches);
        $ys = array_map('floatval', $matches[1]);

        $this->assertCount(3, $ys);
        $this->assertGreaterThanOrEqual(
            $height * 0.18,
            min($ys),
            'peaks must sit just below the Stat description without flattening the chart'
        );
    }
}
