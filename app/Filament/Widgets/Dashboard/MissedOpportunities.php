<?php

namespace App\Filament\Widgets\Dashboard;

use App\Clickhouse\Models\MissedOpportunity;
use App\Dto\CardQueryStatement;
use App\Filament\Widgets\Dashboard\Concerns\DashboardWidget;
use App\Filament\Widgets\Dashboard\Concerns\ExplainsCardQuery;
use App\Support\Clickhouse\BuilderSql;

class MissedOpportunities extends DashboardWidget implements ExplainsCardQuery
{
    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Missed Opportunities';

    protected function getTooltipDescription(): ?string
    {
        return 'Vehicles scanned but not recovered before the assignment ended, counted once per assignment.';
    }

    public function getDatasetLabel(): string
    {
        return 'Missed Opportunities';
    }

    /**
     * Matches what `primary` actually RENDERS in this panel -- oklch(0.704 0.187 23.186),
     * i.e. rgb(255, 102, 102). The compact card's sparkline is coloured by the `fi-color-primary`
     * CSS class while the EXPANDED chart is coloured by this value, so a mismatch here shows up as
     * one card drawn in two different colours. Same value LiveHits uses for `danger`.
     */
    public function getRgbColor(): array
    {
        return [255, 102, 102];
    }

    protected function getModelQueryForDates(string $startDate, string $endDate): int
    {
        return (int) MissedOpportunity::query()
            ->forOrg(auth()->user()->org_id)
            ->notNicb()
            ->between($startDate, $endDate, $this->viewerTimezone())
            ->selectTotal()
            ->value('total');
    }

    /**
     * The "Upgrade to Pro" upsell (DEV-2462) was REMOVED with this card's Pro gate. The card
     * can now only lock for a viewer holding neither ADMIN nor USER, and buying Pro would not
     * unlock it -- the copy and its upgrade link would have been actively misleading in the
     * one case they could still render. The base defaults (null / null) apply.
     *
     * The sidebar badge on MissedOpportunityResource is NOT affected: the resource itself is
     * still gated on FeatureEnum::MISSED_OPPORTUNITY, so its upsell remains correct.
     */

    /**
     * `upgrade.from` pushes the slug onto session('upgrade_sources') and redirects to
     * Subscription, so the page opens naming Missed Opportunities rather than cold. That is
     * the same route the sidebar's gated pages reach via HasProFeature.
     */
    public function getCardQueryStatements(): array
    {
        [$start, $end] = $this->headlineWindow();

        return [new CardQueryStatement(
            label: 'Headline total',
            // ->limit(1) mirrors getModelQueryForDates()'s ->value('total'): value() is first(),
            // which compiles a `limit 1` the emitted statement must carry too.
            sql: BuilderSql::of(
                MissedOpportunity::query()
                    ->forOrg(auth()->user()->org_id)
                    ->notNicb()
                    ->between($start, $end, $this->viewerTimezone())
                    ->selectTotal()
                    ->limit(1)
            ),
        )];
    }
}
