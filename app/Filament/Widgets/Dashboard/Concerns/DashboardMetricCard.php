<?php

namespace App\Filament\Widgets\Dashboard\Concerns;

use Filament\Widgets\StatsOverviewWidget\Stat;

interface DashboardMetricCard
{
    /**
     * Rendered in place of a withheld metric by lockedStat().
     *
     * On the interface rather than the trait so tests, QA and sibling tickets can reference
     * it by name (PHP also forbids reading a trait constant through the trait name).
     *
     * `final` on purpose. lockedStat() reads it as DashboardMetricCard::LOCKED_VALUE, not
     * static::, so a card redeclaring the constant (legal since PHP 8.1) would be silently
     * ignored -- it would render the em dash while its author believed otherwise. With
     * `final` that mistake is a fatal at the point it is made, which matters for a contract
     * the nine sibling card tickets implement.
     */
    final public const LOCKED_VALUE = '—';

    public function getCardKey(): string;

    public function toStat(?string $selectedCard = null): Stat;

    /**
     * Whether the viewing org may see this card's VALUE. False keeps the card's grid slot
     * but routes it through lockedStat() instead of toStat(), and drops it from
     * CompanyMetricsOverview::expandableCards().
     *
     * Static because expandableCards() filters class-strings without instantiating.
     *
     * RendersDashboardStat defaults this to the card's OWN canView() -- NOT to a bare
     * `true` -- so a card arriving with its own gate is honoured on the grid's mount()-less
     * adapter path rather than silently rendering its value. Implementers are therefore
     * opted IN to their own gate and override this method only to keep a card VISIBLE but
     * value-less. See that trait for the full implementer contract; it is the authority,
     * and this summary must not drift from it.
     *
     * MissedOpportunities, StagingLiveHitLeader, UniqueLprCaseClaims, UniqueScansLeaderboard,
     * and LprRepossessions all override it. For the other 7 cards canView() is the same role
     * check the grid already enforces, so the default is a behavioural no-op for them.
     */
    public static function canViewCardData(): bool;

    /**
     * The card as rendered when canViewCardData() is false: same grid slot, no value.
     *
     * MUST NOT compute the underlying metric -- CompanyMetricsOverview::getStats() branches
     * to this instead of toStat() precisely so the query never runs for an org that may not
     * see the result.
     */
    public function lockedStat(?string $selectedCard = null): Stat;
}
