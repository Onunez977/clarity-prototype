<?php

namespace App\Filament\Widgets\Dashboard\Concerns;

use Filament\Support\Enums\IconPosition;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

trait RendersDashboardStat
{
    abstract public function getCardKey(): string;

    abstract public static function canView(): bool;

    /**
     * Filament Stat chrome for the Company Metrics grid: click-to-expand, HotlistCount's
     * top-right info icon (`data-description`), `big-stat-chart` for the sparkline, and a
     * thin brand-red ring on whichever card is currently expanded (the dashboard rebuild).
     */
    protected function dashboardStat(Stat $stat, ?string $selectedCard = null): Stat
    {
        return $stat
            ->key($this->dashboardStatKey())
            ->extraAttributes($this->dashboardStatAttributes($selectedCard));
    }

    /**
     * One source for the Livewire key, so the locked and unlocked renderings of the same
     * card can never diverge.
     */
    protected function dashboardStatKey(): string
    {
        return 'dashboard-stat-'.$this->getCardKey();
    }

    /**
     * The card's display label. Lives here rather than on DashboardWidget because
     * lockedStat() below is shared by every implementer, including the two that do NOT
     * extend DashboardWidget (RecoveryLeader, KitUsers) -- the Company Metrics foundation.
     *
     * Three sources, in order, because Filament exposes the heading differently per base
     * class and neither is guaranteed to exist:
     *   1. getHeading()      -- ChartWidget (public) and StatsOverviewWidget;
     *   2. getTableHeading() -- TableWidget's accessor for its own static $heading;
     *                           KitUsers sets that, so without this arm its label degrades
     *                           to the class basename 'KitUsers';
     *   3. the class basename -- last resort for a genuinely heading-less card.
     *
     * getHeading() is called through method_exists() rather than assumed: TableWidget has
     * none, so an unguarded call fatals on a trait user -- and only once a card is actually
     * locked, long after the change that introduced it.
     *
     * IMPLEMENTER CONSTRAINT: getHeading() MUST be cheap and side-effect-free. lockedStat()
     * calls this method, and lockedStat()'s whole purpose is to render a card WITHOUT
     * computing its metric. A getHeading() that queries -- as
     * MissedOpportunityClientGraph::getHeading() does today via clients() -- would defeat
     * that and run the very query the gate exists to suppress.
     */
    public function getCardLabel(): string
    {
        $heading = \method_exists($this, 'getHeading') ? $this->getHeading() : null;

        // Probed through the accessor, never as static::$heading -- the property is static on
        // TableWidget subclasses but an INSTANCE property on ChartWidget ones, and reading an
        // instance property statically is an Error.
        if ($heading === null && \method_exists($this, 'getTableHeading')) {
            $heading = $this->getTableHeading();
        }

        return match (true) {
            $heading instanceof Htmlable => $heading->toHtml(),
            \is_string($heading) => $heading,
            default => \class_basename(static::class),
        };
    }

    /**
     * Whether the viewing org may see this card's VALUE.
     *
     * Defaults to the card's OWN canView(), not to a bare `true`. That matters: cards are
     * built as adapters and never Livewire-mounted, so canView() is otherwise not enforced
     * on the compact path at all. Deferring to it means a card that already carries a gate
     * -- MissedOpportunityClientGraph's Pennant check being the obvious example -- is
     * honoured the moment it joins cards(), instead of silently rendering its value to an
     * org that may not see it. A no-op for the 7 ungated cards, whose
     * canView() is the same hasAnyRole(ADMIN, USER) the grid itself already enforces.
     * staging-live-hit-leader ANDs a FeatureEnum through Pennant onto that role check;
     * missed-opportunities is the other production override.
     *
     * IMPLEMENTER CONTRACT for the sibling card tickets:
     *   - THERE IS NO REMOVAL PATH ON THE GRID. Both routes render the locked card:
     *     getStats() iterates cards() unconditionally, so canView() false simply makes this
     *     method false too and you get the permanent locked placeholder. If a card must not
     *     appear at all, take it out of cards(); do not expect canView() to hide it.
     *   - Prefer overriding canView() when the gate is about ACCESS (it also governs the
     *     expanded widget's own mount()); override this method when the card should stay
     *     visible but value-less for an otherwise-permitted viewer.
     *   - Derive Pro entitlement the way okf/concepts/filament-conventions.md records --
     *     a FeatureEnum through Pennant, paired with the session('admin_impersonator')
     *     bypass. "Bare" there means a raw Feature::active() with no FeatureEnum and no
     *     bypass; the sanctioned shape IS an explicit
     *     `static::canView() && (Feature::active(FeatureEnum::X->slug()) || session()->has('admin_impersonator'))`,
     *     which is what staging-live-hit-leader ships and what
     *     okf/concepts/feature-flags.md documents. Note the AND: the Pro term composes WITH
     *     the role check, it does not replace it -- dropping the role term would offer an
     *     entitled org's non-ADMIN/USER member a card the grid itself rejects.
     *     HasProFeature is unusable on this path because it hooks Livewire boot() and
     *     redirects the whole page rather than one card.
     *   - MUST be TOTAL with NO authenticated user -- it must return a bool rather than
     *     error -- and must therefore GUARD any auth() dereference. Dashboard::cardWidgets()
     *     reaches this from contexts that never authenticated, so an unguarded
     *     auth()->user()->... errors.
     *     NOTE (DEV-2462): "total" constrains the ERROR behaviour, not the VALUE. This
     *     default answers `true` for a guest; MissedOpportunities, the first real gate,
     *     deliberately answers `false` (guarding with auth()->check() first, so it is still
     *     total). Either value is contract-compliant. If you copy that card, copy the guard
     *     ordering, not the return value.
     *   - MUST be cheap: it is evaluated per card, per render, on two separate paths.
     */
    public static function canViewCardData(): bool
    {
        // Guest short-circuit, and it is not a hole. This answers "may the VIEWING ORG see
        // this card's value" -- with no viewer the question is meaningless, and the only
        // consumer that renders a value (CompanyMetricsOverview::getStats()) is unreachable
        // without auth because that widget's mount() aborts first. The other consumer,
        // Dashboard::cardWidgets(), needs only the array SHAPE, so it must not error here.
        if (auth()->guest()) {
            return true;
        }

        return static::canView();
    }

    /**
     * The locked rendering: the card holds its grid slot but carries no value.
     *
     * Three things are deliberately absent, and each is load-bearing:
     *   - the metric itself is never computed (this method takes no value argument and calls
     *     no getCardTotal()/chartSeries()), so a gated org's query does not run at all;
     *   - no ->chart(), so the sparkline cannot leak the shape of the withheld series;
     *   - none of dashboardStatAttributes()' click affordances (wire:click, x-bind,
     *     data-description), so the card cannot be expanded even if its key somehow reached
     *     Dashboard::toggleCard().
     *
     * `big-stat-chart` IS kept: theme.css hangs the grid's height floor off that class, so
     * dropping it would make a locked card shorter than its neighbours.
     *
     * DISCHARGED (DEV-2462, second pass). This paragraph twice deferred the locked card's
     * PRESENTATION -- an upsell line, a padlock icon -- as "a product decision". The operator
     * has now made it: a locked card may carry a short upsell line and link to the page that
     * sells the entitlement. What is designed HERE is only the seam
     * (lockedStatDescription() / lockedStatUrl()); the copy and the destination stay on the
     * card, because this trait cannot know WHICH entitlement gated a given card and a blanket
     * "Upgrade to Pro" would be wrong for a card ever gated on something else. Both hooks
     * default to null, so a sibling that adds nothing -- including staging-live-hit-leader --
     * renders exactly the bare placeholder this method rendered before. That opt-out is
     * deliberate for this card (no AC required an upsell); gating through canViewCardData()
     * rather than HasProFeature also means nothing pushes the feature onto
     * session('upgrade_sources'), so Subscription reports source_feature 'direct' for it.
     *
     * The three absences above survive that: the description is a literal string, so it
     * neither computes nor reveals the metric, and ->url() navigates rather than expanding
     * the card, so lockedStatAttributes() still carries no toggle affordance.
     */
    public function lockedStat(?string $selectedCard = null): Stat
    {
        $stat = Stat::make($this->getCardLabel(), DashboardMetricCard::LOCKED_VALUE)
            ->key($this->dashboardStatKey())
            ->color('gray')
            ->extraAttributes($this->lockedStatAttributes());

        if (($description = $this->lockedStatDescription()) !== null) {
            $stat->description($description)
                ->descriptionIcon('heroicon-m-lock-closed', IconPosition::Before)
                ->descriptionColor('info');
        }

        // Unconditional: url(null) leaves Filament's stat blade on its `div` tag, so an
        // opted-out card is byte-identical to the pre-DEV-2462 rendering.
        return $stat->url($this->lockedStatUrl());
    }

    /**
     * The locked card's upsell line, or null for the bare placeholder.
     *
     * MUST be a literal -- it is rendered on the path whose entire purpose is to avoid
     * computing the withheld metric, so anything derived from that metric defeats the gate.
     * Keep it short: it renders inside a 160px card under a wrapping span.
     */
    protected function lockedStatDescription(): ?string
    {
        return null;
    }

    /**
     * One truncated line naming a leaderboard card's leading kit, with the full name on hover.
     *
     * The truncate is load-bearing, not cosmetic: kit names are unbounded, and theme.css keeps
     * min-h-47 as a FLOOR ("a wrapping label grows the card"), so a name that wraps to two lines
     * makes its card taller than the row. That is the parity DEV-2324 bought by dropping these
     * names, and 304d3c77e removed this same guard along with them.
     *
     * A BLANK name is NOT handled here. That belongs in the query, per the house guard
     * {@see KitScanPathsQuery::handle()} / {@see ScanPlateStatsBatchQuery} and
     * `okf/concepts/scout-clickhouse-queries.md:47`, which yields an actionable `Kit 1234`
     * instead of a placeholder. `null` below means no leader row at all, which is a different
     * condition and the only one this renders as N/A.
     */
    protected function leaderKitDescription(?string $name): Htmlable
    {
        $name = $name ?? 'N/A';

        return new HtmlString(
            '<span class="block min-w-0 truncate" title="'.e($name).'">Top Kit: '.e($name).'</span>'
        );
    }

    /**
     * Where the locked card links, or null to leave it unlinked.
     *
     * Prefer the existing `upgrade.from` route over Subscription::getUrl() -- it records the
     * feature that drove the click in session('upgrade_sources'), which is what makes the
     * Subscription page name the feature the viewer was reaching for. Same plumbing
     * HasProFeature uses for a whole gated page.
     */
    protected function lockedStatUrl(): ?string
    {
        return null;
    }

    /**
     * Expanded detail sits in a full-width Group under the card grid. Compact
     * Livewire mounts are unused (CompanyMetricsOverview builds Stats as adapters).
     *
     * @return int|string|array<string, int|null>
     */
    public function getColumnSpan(): int|string|array
    {
        if (\property_exists($this, 'expanded') && $this->expanded) {
            return 'full';
        }

        return $this->columnSpan;
    }

    /**
     * This card's help text, shown in the info-icon tooltip the published
     * `filament-widgets::stats-overview-widget.stat` override renders from `data-description`.
     * Null falls back to the generic click hint.
     *
     * Mirrors ClientStatWidget::getTooltipDescription(), the same hook the client-analytics
     * stats already use.
     *
     * IMPLEMENTER CONSTRAINT: MUST be a literal. Cards are built as adapters on the compact
     * path, so anything derived from the metric would run the card's query a second time --
     * and on a locked card the value is withheld precisely so it is never computed.
     *
     * Keep it to one or two short sentences: the tooltip panel is `w-64`.
     */
    protected function getTooltipDescription(): ?string
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function dashboardStatAttributes(?string $selectedCard = null): array
    {
        $key = $this->getCardKey();
        $attributes = [
            'class' => 'cursor-pointer big-stat-chart',
            'data-dashboard-card' => $key,
            'data-description' => $this->getTooltipDescription() ?? 'Click for more details',
            'title' => 'Click for more details',
            'wire:click' => "\$parent.toggleCard('{$key}')",
            'x-bind:class' => "{ 'dashboard-stat-open': \$wire.\$parent && \$wire.\$parent.expandedCard === '{$key}' }",
            'x-bind:data-dashboard-open' => "\$wire.\$parent && \$wire.\$parent.expandedCard === '{$key}' ? 'true' : null",
        ];

        if ($selectedCard === $key) {
            $attributes['class'] .= ' dashboard-stat-open';
            $attributes['data-dashboard-open'] = 'true';
        }

        return $attributes;
    }

    /**
     * Locked chrome: keeps the grid geometry (`big-stat-chart`), drops every click
     * affordance. `data-dashboard-locked` is what tests and QA assert on.
     *
     * @return array<string, string>
     */
    protected function lockedStatAttributes(): array
    {
        return [
            'class' => 'big-stat-chart',
            'data-dashboard-card' => $this->getCardKey(),
            'data-dashboard-locked' => 'true',
        ];
    }
}
