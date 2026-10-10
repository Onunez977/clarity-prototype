<?php

declare(strict_types=1);

namespace App\Support\Otto;

use App\Enums\FeatureEnum;
use App\Filament\Widgets\Client\Concerns\ClientStatWidget;
use App\Filament\Widgets\Dashboard\Concerns\DashboardMetricCard;
use App\Filament\Widgets\Dashboard\HotlistCount;
use App\Filament\Widgets\KitUserRecoveries\RecoveryStatsWidget;
use App\Models\Org;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/**
 * Reads one Filament stat widget for Otto (DEV-2706).
 *
 * The widget's own access check runs before its query. A premium lock returns
 * an account-manager upsell and does not query. A role or permission lock does
 * not suggest an upgrade.
 */
final class WidgetMetric
{
    public const COMPANY_METRICS_DENIED = 'Company Metrics are available to organization admins and users.';

    public const OPEN_ASSIGNMENTS_DENIED = 'Open assignment totals are available to organization admins and users.';

    public const CLIENT_ANALYTICS_UPSELL = 'Client Analytics is part of Clarity Pro. If your organization does not have it yet, ask your Clarity account manager about enabling it.';

    public const MISSED_OPPORTUNITY_UPSELL = 'Missed Opportunities is part of Clarity Pro. If your organization does not have it yet, ask your Clarity account manager about enabling it.';

    public const CLIENT_ANALYTICS_ROLE = 'Client Analytics is limited to organization admins.';

    public const KIT_RECOVERIES_DENIED = 'Kit User Recoveries are limited to people who can view that report.';

    /**
     * @param  class-string<DashboardMetricCard>  $card
     */
    public function companyCard(User $user, string $card, ?string $period): string
    {
        if ($user->org_id === null) {
            return 'Metrics are available for an organization account.';
        }

        return $this->withUser($user, function () use ($card, $period): string {
            $label = $this->companyCardLabel($card);

            if (! $this->allowsCompanyCard($card)) {
                return self::COMPANY_METRICS_DENIED;
            }

            if (! $card::canViewCardData()) {
                return $this->lockedCompanyCard(new $card, $label);
            }

            $period ??= 'this_month';
            $normalized = $this->normalizePeriod($period) ?? $period;
            if (! in_array($normalized, ['today', 'last_7d', 'this_month', 'last_month'], true)) {
                return $this->unsupportedPeriod($label, $normalized, ['today', 'last_7d', 'this_month', 'last_month']);
            }

            $widget = new $card;
            $this->setPublicProperty($widget, 'date', $normalized);
            $this->setPublicProperty($widget, 'filters', ['date_toggles' => $normalized]);

            try {
                $line = $this->statLine($widget->toStat());
            } catch (Throwable $exception) {
                report($exception);
                $line = '- '.$label.': unavailable';
            }

            return $label.' ('.$this->periodPhrase($normalized).")\n"
                ."Organization-wide for this period.\n"
                .$line;
        });
    }

    /**
     * @param  class-string<DashboardMetricCard>  $card
     */
    public function companyCardLabel(string $card): string
    {
        return $this->plain((new $card)->getCardLabel()) ?? 'Metric';
    }

    public function openAssignments(User $user): string
    {
        if ($user->org_id === null) {
            return 'Metrics are available for an organization account.';
        }

        return $this->withUser($user, function (): string {
            if (! HotlistCount::canView()) {
                return self::OPEN_ASSIGNMENTS_DENIED;
            }

            try {
                $lines = array_map(
                    fn (Stat $stat): string => $this->statLine($stat),
                    $this->widgetStats((new HotlistCount)->failLoudly()),
                );
            } catch (Throwable $exception) {
                report($exception);
                $lines = ['- Open assignment totals: unavailable'];
            }

            if ($lines === []) {
                $lines = ['- Open assignment totals: unavailable'];
            }

            return "Open assignment totals\n"
                ."Current book, not a date range.\n"
                .implode("\n", $lines);
        });
    }

    /**
     * @param  class-string<ClientStatWidget>  $widget
     * @param  list<string>  $periods
     */
    public function clientWidget(
        User $user,
        string $widgetClass,
        ?string $period,
        ?int $clientId,
        ?string $clientName,
        bool $clientAccepted,
        bool $clientRequired,
        array $periods,
        ?FeatureEnum $feature,
        ?string $note,
    ): string {
        if ($user->org_id === null) {
            return 'Metrics are available for an organization account.';
        }

        if ($clientId !== null && $clientId <= 0) {
            $clientId = null;
        }

        $clientName = $this->blankToNull($clientName);

        return $this->withUser($user, function () use ($user, $widgetClass, $period, $clientId, $clientName, $clientAccepted, $clientRequired, $periods, $feature, $note): string {
            $denial = $this->clientAnalyticsDenial($user);
            if ($denial !== null) {
                return $denial;
            }

            $label = $this->clientWidgetLabel($widgetClass);
            $client = null;

            if ($clientAccepted && ($clientRequired || $clientId !== null || $clientName !== null)) {
                if ($clientId === null && $clientName === null) {
                    return 'Name the client for '.$label.'.';
                }

                $resolved = $this->resolveClient($user, $clientId, $clientName);
                if (! $resolved instanceof Org) {
                    return $resolved;
                }

                $client = $resolved;
            }

            $widget = new $widgetClass;
            if (! $widget::canView()) {
                if ($feature === FeatureEnum::MISSED_OPPORTUNITY && ! $this->featureActive($user, $feature)) {
                    return self::MISSED_OPPORTUNITY_UPSELL;
                }

                return $label.' is not available for this account.';
            }

            if ($periods !== []) {
                $period ??= 'this_month';
                $normalized = $this->normalizePeriod($period) ?? $period;
                if (! in_array($normalized, $periods, true)) {
                    return $this->unsupportedPeriod($label, $normalized, $periods);
                }
            } else {
                $normalized = 'this_month';
            }

            $widget->org_id = (int) $user->org_id;
            $widget->date_type = $normalized;
            $widget->client_org_id = $client instanceof Org ? (int) $client->id : null;

            try {
                $lines = array_map(
                    fn (Stat $stat): string => $this->statLine($stat),
                    $this->widgetStats($widget),
                );
            } catch (Throwable $exception) {
                report($exception);
                $lines = ['- '.$label.': unavailable'];
            }

            if ($lines === []) {
                $lines = ['- '.$label.': unavailable'];
            }

            $heading = $client instanceof Org ? $client->name.', '.$label : $label;
            if ($periods !== []) {
                $heading .= ' ('.$this->periodPhrase($normalized).')';
            }
            if ($note !== null) {
                $heading .= "\n".$note;
            }

            return $heading."\n".implode("\n", $lines);
        });
    }

    /**
     * @param  class-string<ClientStatWidget>  $widgetClass
     */
    public function clientWidgetLabel(string $widgetClass): string
    {
        $widget = new $widgetClass;

        return $this->plain($this->invoke($widget, 'getLabel')) ?? 'Metric';
    }

    public function kitRecoveries(User $user, ?string $period): string
    {
        if ($user->org_id === null) {
            return 'Metrics are available for an organization account.';
        }

        return $this->withUser($user, function () use ($user, $period): string {
            if (! RecoveryStatsWidget::canView()) {
                return self::KIT_RECOVERIES_DENIED;
            }

            $period ??= 'this_month';
            $normalized = $this->normalizePeriod($period) ?? $period;
            if (! in_array($normalized, ['this_month', 'last_month'], true)) {
                return $this->unsupportedPeriod('Kit user recoveries', $normalized, ['this_month', 'last_month']);
            }

            $timezone = $user->timezone ?: 'UTC';
            if ($normalized === 'last_month') {
                $start = now(tz: $timezone)->startOfMonth()->subMonth()->startOfDay();
                $end = now(tz: $timezone)->startOfMonth()->subMonth()->endOfMonth()->endOfDay();
            } else {
                $start = now(tz: $timezone)->startOfMonth()->startOfDay();
                $end = now(tz: $timezone);
            }

            $widget = (new RecoveryStatsWidget)->failLoudly();
            $widget->pageFilters = [
                'start_date' => $start,
                'end_date' => $end,
            ];

            try {
                $lines = [];
                foreach ($this->widgetStats($widget) as $stat) {
                    $label = $this->plain($stat->getLabel());
                    $value = $this->plain($stat->getValue());
                    if ($label !== null && strcasecmp($label, 'Leader') === 0 && ($value === null || strcasecmp($value, 'N/A') === 0)) {
                        continue;
                    }

                    $lines[] = $this->statLine($stat);
                }
            } catch (Throwable $exception) {
                report($exception);
                $lines = ['- Total Recoveries: unavailable'];
            }

            if ($lines === []) {
                $lines = ['- Total Recoveries: unavailable'];
            }

            return 'Kit user recoveries ('.$this->periodPhrase($normalized).")\n".implode("\n", $lines);
        });
    }

    /**
     * Borrows the user's identity for the widget's auth()->user() reads.
     *
     * setUser()/forgetUser(), never login()/logout(): those fire auth events, touch the
     * session, and logout() cycles the user's remember_token, which signs them out of
     * every "remember me" device.
     *
     * @param  callable(): string  $callback
     */
    private function withUser(User $user, callable $callback): string
    {
        $previousUser = auth()->hasUser() ? auth()->user() : null;
        $previousTeam = getPermissionsTeamId();

        if ($user->org_id !== null) {
            setPermissionsTeamId($user->org_id);
        }

        auth()->setUser($user);

        try {
            return $callback();
        } finally {
            if ($previousUser instanceof User) {
                auth()->setUser($previousUser);
            } else {
                auth()->forgetUser();
            }

            if ($previousTeam !== null) {
                setPermissionsTeamId($previousTeam);
            }
        }
    }

    /**
     * @param  class-string<DashboardMetricCard>  $card
     */
    private function allowsCompanyCard(string $card): bool
    {
        return method_exists($card, 'canView') && $card::canView();
    }

    private function lockedCompanyCard(DashboardMetricCard $card, string $label): string
    {
        $description = $this->plain($card->lockedStat()->getDescription());
        if ($description !== null && $this->isUpsell($description)) {
            return $description;
        }

        return $label.' is not available for this account.';
    }

    private function isUpsell(string $description): bool
    {
        $text = strtolower($description);

        return str_contains($text, 'clarity pro')
            || str_contains($text, 'account manager')
            || str_contains($text, 'upgrade');
    }

    private function clientAnalyticsDenial(User $user): ?string
    {
        if (! $this->featureActive($user, FeatureEnum::CLIENT_ANALYTICS)) {
            return self::CLIENT_ANALYTICS_UPSELL;
        }

        if (! $user->hasRole(RoleEnum::ADMIN->value)) {
            return self::CLIENT_ANALYTICS_ROLE;
        }

        return null;
    }

    private function featureActive(User $user, FeatureEnum $feature): bool
    {
        return Feature::for($user)->active($feature->slug())
            || session()->has('admin_impersonator');
    }

    private function resolveClient(User $user, ?int $clientId, ?string $clientName): Org|string
    {
        $org = $user->org;
        if ($org === null) {
            return 'Metrics are available for an organization account.';
        }

        if ($clientId !== null) {
            if (! $org->hasHotlistClient($clientId)) {
                return 'That client is not one of this organization\'s clients.';
            }

            $client = Org::query()->find($clientId);

            return $client instanceof Org
                ? $client
                : 'That client is not one of this organization\'s clients.';
        }

        /** @var Collection<int, int|string> $clientIds */
        $clientIds = $org->hotlists()
            ->whereNotNull('client_org_id')
            ->distinct()
            ->pluck('client_org_id');

        // An exact name wins over its substring matches ("Ally" beside "Ally Financial").
        $exact = Org::query()
            ->whereIn('id', $clientIds)
            ->where('name', 'ilike', $this->escapeLike((string) $clientName))
            ->limit(2)
            ->get();

        if ($exact->count() === 1) {
            /** @var Org $client */
            $client = $exact->first();

            return $client;
        }

        $matches = Org::query()
            ->whereIn('id', $clientIds)
            ->where('name', 'ilike', '%'.$this->escapeLike((string) $clientName).'%')
            ->orderBy('name')
            ->limit(6)
            ->get();

        if ($matches->isEmpty()) {
            return 'No client in this organization matched that name.';
        }

        if ($matches->count() > 1) {
            return 'Several clients matched that name. Ask which one: '.$matches->pluck('name')->implode(', ').'.';
        }

        /** @var Org $client */
        $client = $matches->first();

        return $client;
    }

    /**
     * @return list<Stat>
     */
    private function widgetStats(object $widget): array
    {
        $stats = $this->invoke($widget, 'getStats');

        return is_array($stats) ? array_values(array_filter($stats, fn (mixed $stat): bool => $stat instanceof Stat)) : [];
    }

    private function statLine(Stat $stat): string
    {
        $label = $this->plain($stat->getLabel()) ?? 'Metric';
        $value = $this->plain($stat->getValue()) ?? 'unavailable';
        $description = $this->plain($stat->getDescription());
        $line = '- '.$label.': '.$value;

        if ($description !== null) {
            $line .= ' ('.$description.')';
        }

        return $line;
    }

    private function invoke(object $object, string $method): mixed
    {
        return (new ReflectionMethod($object, $method))->invoke($object);
    }

    private function setPublicProperty(object $object, string $property, mixed $value): void
    {
        if (! property_exists($object, $property)) {
            return;
        }

        $reflection = new ReflectionProperty($object, $property);
        if ($reflection->isPublic()) {
            $object->{$property} = $value;
        }
    }

    private function plain(mixed $value): ?string
    {
        if ($value instanceof Htmlable) {
            $value = $value->toHtml();
        }

        if (! is_scalar($value)) {
            return null;
        }

        $text = trim(html_entity_decode(strip_tags((string) $value)));

        return $text === '' ? null : $text;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function unsupportedPeriod(string $metric, string $period, array $allowed): string
    {
        $phrases = array_map(fn (string $key): string => $this->periodPhrase($key), $allowed);

        return $metric.' does not include '.$this->periodPhrase($period).'. Use '.implode(', ', $phrases).'.';
    }

    private function periodPhrase(string $period): string
    {
        return match ($period) {
            'today' => 'Today',
            'last_7d' => 'Last 7 days',
            'this_month' => 'This month',
            'last_month' => 'Last month',
            'ytd' => 'Year to date',
            'last_30d' => 'Last 30 days',
            'last_90d' => 'Last 90 days',
            default => $period,
        };
    }

    private function normalizePeriod(?string $period): ?string
    {
        $period = $this->blankToNull($period);
        if ($period === null) {
            return null;
        }

        $key = str_replace(['-', ' '], '_', strtolower($period));

        return match ($key) {
            'today' => 'today',
            'last_7d', 'last_7_days', 'last7d' => 'last_7d',
            'this_month', 'mtd', 'month_to_date' => 'this_month',
            'last_month' => 'last_month',
            'ytd', 'year_to_date' => 'ytd',
            'last_30d', 'last_30_days' => 'last_30d',
            'last_90d', 'last_90_days' => 'last_90d',
            default => $key,
        };
    }

    private function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
