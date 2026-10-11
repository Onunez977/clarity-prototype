<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The open-assignment header widget: current book totals, not a date range.
 */
class HotlistCountTool extends WidgetMetricTool
{
    public function description(): Stringable|string
    {
        return 'Read the current open-assignment totals: total open assignments, open assignments with plates, open assignments missing plates, missing plates percentage, active kit users, and staging size. These are the current book, not a date range. Return the numbers only when this user can see them. A role denial is not an upgrade.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function read(Request $request): string
    {
        return $this->metrics->openAssignments($this->user);
    }
}
