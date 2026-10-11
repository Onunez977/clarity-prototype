<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Kit User Recoveries totals from RecoveryStatsWidget.
 */
class RecoveryStatsTool extends WidgetMetricTool
{
    public function description(): Stringable|string
    {
        return 'Read kit user recovery totals for this organization. Optional period: this month or last month. Omit for this month. Return the number only when this user can view that report. A permission denial is not an upgrade.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()->description('Optional. this month or last month. Omit for this month.'),
        ];
    }

    protected function read(Request $request): string
    {
        return $this->metrics->kitRecoveries(
            $this->user,
            isset($request['period']) ? (string) $request['period'] : null,
        );
    }
}
