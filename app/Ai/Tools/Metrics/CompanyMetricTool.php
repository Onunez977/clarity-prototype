<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Filament\Widgets\Dashboard\Concerns\DashboardMetricCard;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * One Company Metrics card. The period is the card's own date range.
 */
abstract class CompanyMetricTool extends WidgetMetricTool
{
    /**
     * @return class-string<DashboardMetricCard>
     */
    abstract protected function card(): string;

    public function description(): Stringable|string
    {
        $name = $this->metrics->companyCardLabel($this->card());

        return 'Read this organization\'s '.$name.' metric. Optional period: today, last 7 days, this month, or last month. Omit for this month. Return the number only when this user can see it. A role denial is not an upgrade. The total is organization-wide for the period, not a filtered table count.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()->description('Optional. today, last 7 days, this month, or last month. Omit for this month.'),
        ];
    }

    protected function read(Request $request): string
    {
        return $this->metrics->companyCard(
            $this->user,
            $this->card(),
            isset($request['period']) ? (string) $request['period'] : null,
        );
    }
}
