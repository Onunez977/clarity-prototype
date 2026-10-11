<?php

namespace App\Filament\Widgets\Dashboard;

/**
 * SVG sparkline for Company Metrics stats. Filament's Chart.js Stat canvas is
 * initialized once via Alpine x-load and does not survive Livewire morphing the
 * Filament schema wrapper when the date filter changes (DEV-2324).
 */
final class DashboardSparkline
{
    /**
     * @param  array<int, float|int>  $values
     * @return array{line: string, area: string, viewBox: string}
     */
    public static function paths(array $values, int $width = 300, int $height = 80): array
    {
        $values = array_values(array_map(static fn (mixed $value): float => (float) $value, $values));

        if (\count($values) < 2) {
            array_unshift($values, 0.0);
        }

        $min = min($values);
        $max = max($values);
        $range = $max - $min;
        $flat = $range < 0.0001;

        if ($flat) {
            // A perfectly flat series maps every point to the same Y. SVG getBBox() for a
            // horizontal path has height 0, so Playwright's toBeVisible() (and anyone reading
            // "is there a sparkline") treats the line as hidden even though the stroke paints.
            // Nudge the range so the midline still reads flat but the path has a measurable box.
            $range = 1.0;
            $min -= 0.5;
        }

        // Small top pad so peaks sit just below the Stat description without shrinking the chart.
        $padTop = $height * 0.20;
        $padBottom = $height * 0.18;
        $plotHeight = $height - $padTop - $padBottom;
        $count = \count($values);
        $step = $width / ($count - 1);

        $points = [];

        foreach ($values as $index => $value) {
            $x = round($index * $step, 2);
            $normalized = ($value - $min) / $range;

            // Tiny vertical extent on a flat series so the stroke has a non-zero bounding box.
            if ($flat) {
                $normalized += ($index % 2 === 0 ? -0.02 : 0.02);
            }

            $y = round($padTop + (1 - $normalized) * $plotHeight, 2);
            $points[] = [$x, $y];
        }

        $line = '';

        foreach ($points as $index => [$x, $y]) {
            $line .= $index === 0 ? "M {$x} {$y}" : " L {$x} {$y}";
        }

        $first = $points[0];
        $last = $points[array_key_last($points)];
        $area = $line." L {$last[0]} {$height} L {$first[0]} {$height} Z";

        return [
            'line' => $line,
            'area' => $area,
            'viewBox' => "0 0 {$width} {$height}",
        ];
    }
}
