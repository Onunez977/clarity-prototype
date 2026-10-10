<?php

namespace App\Filament\Pages;

use App\Enums\FeatureEnum;
use App\Enums\ProTierEnum;
use App\Facades\Analytics;
use App\Jobs\FlushPennant;
use App\Mail\Pro\DowngradeCancellation;
use App\Mail\Pro\DowngradeRequest;
use App\Mail\Pro\SubscriptionReceipt;
use App\Mail\Pro\UpgradeRequest;
use App\Models\Org;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use InsightLPR\BlackAdam\Enum\RoleEnum;

class Subscription extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.subscription';

    protected static ?string $slug = 'subscription';

    protected static ?string $title = null;

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public array $from = [];

    public ?ProTierEnum $currentTier = null;

    public ?string $pendingDowngradeTier = null;

    public ?string $pendingDowngradeDate = null;

    public ?string $sourceFeatureTitle = null;

    public ?string $sourceFeatureDescription = null;

    public ?string $sourceFeatureTier = null;

    /**
     * Same org_meta key as Account → Configuration (`platform_repo_pro_rdn_metrics_push`).
     */
    public bool $rdnMetricsPushEnabled = false;

    public ?string $orgRdnId = null;

    public function mount(): void
    {
        $user = auth()->user();
        $org = $user->org;

        $this->currentTier = $org->proTier();
        $this->from = $this->sanitizeSources(session('upgrade_sources', []));

        $this->orgRdnId = $org->rdn_id;
        $this->rdnMetricsPushEnabled = (bool) ($org->meta?->properties['platform_repo_pro_rdn_metrics_push'] ?? false);

        $this->resolveSourceFeature();
        $this->loadPendingDowngrade($org);

        Analytics::track('page-view', $user, [
            'action' => 'view',
            'current_tier' => $this->currentTier->value,
            'source_feature' => end($this->from) ?: 'direct',
            'source_features' => $this->from ?: ['direct'],
        ], page: 'subscription');
    }

    public function requestUpgrade(string $targetTier): void
    {
        if (! auth()->user()->hasRole(RoleEnum::ADMIN->value)) {
            return;
        }

        $tier = ProTierEnum::from($targetTier);

        if (! $this->currentTier->isSelfSubscribable() || ! $tier->isSelfSubscribable()) {
            return;
        }

        $user = auth()->user();
        $org = $user->org;

        Analytics::track('pro-request', $user, [
            'action' => 'upgrade',
            'current_tier' => $this->currentTier->value,
            'target_tier' => $tier->value,
            'source_feature' => end($this->from) ?: 'direct',
            'source_features' => $this->from ?: ['direct'],
            'price' => $tier->price(),
        ], page: 'subscription');

        session()->forget('upgrade_sources');

        $meta = $org->meta ?? $org->meta()->create(['properties' => []]);
        $meta->updateProperties([
            'platform_repo_pro' => true,
            'platform_repo_pro_tier' => $tier->value,
            'platform_repo_pro_billing' => $org->proBillingCycle() ?? now()->toDateString(),
            'platform_repo_pro_downgrade_target_tier' => null,
            'platform_repo_pro_downgrade_effective_at' => null,
            'platform_repo_pro_end' => null,
        ]);

        cache()->forget('org_pro_enabled_'.$org->id);
        cache()->forget('org_pro_features_'.$org->id);

        FlushPennant::dispatch();

        if (config('app.pro.sales_email')) {
            Mail::to(config('app.pro.sales_email'))
                ->send(new UpgradeRequest($user, $tier->value, $tier->price()));
        }

        Mail::to($user->email)
            ->send(new SubscriptionReceipt($user, 'upgrade', $this->currentTier->value, $tier->value));

        $this->currentTier = $tier;
        $this->pendingDowngradeTier = null;
        $this->pendingDowngradeDate = null;

        $this->dispatch('pro-upgrade-success');
    }

    public function requestDowngrade(string $targetTier): void
    {
        if (! auth()->user()->hasRole(RoleEnum::ADMIN->value)) {
            return;
        }

        $tier = ProTierEnum::from($targetTier);

        if (! $this->currentTier->isSelfSubscribable() || ! $tier->isSelfSubscribable()) {
            return;
        }

        $user = auth()->user();
        $org = $user->org;

        Analytics::track('pro-request', $user, [
            'action' => 'downgrade',
            'current_tier' => $this->currentTier->value,
            'target_tier' => $tier->value,
        ], page: 'subscription');

        $effectiveDate = $this->resolveDowngradeEffectiveDate($org);

        $meta = $org->meta ?? $org->meta()->create(['properties' => []]);

        $properties = [
            'platform_repo_pro_downgrade_target_tier' => $tier->value,
            'platform_repo_pro_downgrade_effective_at' => $effectiveDate->toIso8601String(),
        ];

        if ($tier === ProTierEnum::BASIC) {
            $properties['platform_repo_pro_end'] = $effectiveDate->toIso8601String();
        }

        $meta->updateProperties($properties);

        cache()->forget('org_pro_enabled_'.$org->id);
        cache()->forget('org_pro_features_'.$org->id);

        FlushPennant::dispatch();

        if (config('app.pro.sales_email')) {
            Mail::to(config('app.pro.sales_email'))
                ->send(new DowngradeRequest($user, $this->currentTier->value, $tier->value));
        }

        Mail::to($user->email)
            ->send(new SubscriptionReceipt($user, 'downgrade', $this->currentTier->value, $tier->value, $effectiveDate));

        $this->pendingDowngradeTier = $tier->value;
        $this->pendingDowngradeDate = $effectiveDate->format('F j, Y');

        $this->dispatch('pro-downgrade-success');
    }

    public function toggleRdnMetricsPush(bool $value): void
    {
        if (! auth()->user()->hasRole(RoleEnum::ADMIN->value)) {
            Notification::make()
                ->title('Unable to update RDN Statistics Push')
                ->body('You do not have permission to change this setting.')
                ->danger()
                ->send();

            return;
        }

        $org = auth()->user()->org;

        if (! in_array($org->proTier(), [ProTierEnum::PRO_LITE, ProTierEnum::CLARITY_PRO, ProTierEnum::CLARITY_PRO_PLUS], true)) {
            Notification::make()
                ->title('Unable to update RDN Statistics Push')
                ->body('This feature is not available for your subscription tier.')
                ->danger()
                ->send();

            return;
        }

        $meta = $org->meta ?? $org->meta()->create(['properties' => []]);

        $old = (bool) ($meta->properties['platform_repo_pro_rdn_metrics_push'] ?? false);

        $meta->updateProperties([
            'platform_repo_pro_rdn_metrics_push' => $value,
        ]);

        activity('rdn_metrics_toggle')
            ->performedOn($meta)
            ->causedBy(auth()->user())
            ->withProperties([
                'org_id' => $org->id,
                'old' => $old,
                'new' => $value,
            ])
            ->log($value ? 'enabled' : 'disabled');

        $this->rdnMetricsPushEnabled = $value;

        Notification::make()
            ->title($value ? 'RDN Statistics Push enabled' : 'RDN Statistics Push disabled')
            ->success()
            ->send();
    }

    public function cancelDowngrade(): void
    {
        if (! auth()->user()->hasRole(RoleEnum::ADMIN->value)) {
            return;
        }

        $user = auth()->user();
        $org = $user->org;

        if (! $org->meta) {
            return;
        }

        $pendingTier = $org->meta->properties['platform_repo_pro_downgrade_target_tier'] ?? null;

        if (! $pendingTier) {
            return;
        }

        $org->meta->updateProperties([
            'platform_repo_pro_downgrade_target_tier' => null,
            'platform_repo_pro_downgrade_effective_at' => null,
            'platform_repo_pro_end' => null,
        ]);

        if (config('app.pro.sales_email')) {
            Mail::to(config('app.pro.sales_email'))
                ->send(new DowngradeCancellation($user, $this->currentTier->value, $pendingTier));
        }

        Mail::to($user->email)
            ->send(new SubscriptionReceipt($user, 'cancellation', $pendingTier, $this->currentTier->value));

        $this->pendingDowngradeTier = null;
        $this->pendingDowngradeDate = null;
    }

    private function loadPendingDowngrade(Org $org): void
    {
        $targetTier = $org->meta?->properties['platform_repo_pro_downgrade_target_tier'] ?? null;
        $effectiveAt = $org->meta?->properties['platform_repo_pro_downgrade_effective_at'] ?? null;

        if ($targetTier && $effectiveAt) {
            $this->pendingDowngradeTier = $targetTier;
            $this->pendingDowngradeDate = Carbon::parse($effectiveAt)->format('F j, Y');
        }
    }

    private function resolveDowngradeEffectiveDate(Org $org): Carbon
    {
        $billingDate = $org->proBillingCycle();

        if (! $billingDate) {
            return now()->addMonth();
        }

        $now = now();
        $anchorDay = min(Carbon::parse($billingDate)->day, $now->daysInMonth);

        $cycleStart = $now->copy()->day($anchorDay);

        if ($cycleStart->isAfter($now)) {
            $cycleStart->subMonth();
        }

        return $cycleStart->addMonth();
    }

    private function resolveSourceFeature(): void
    {
        $slug = end($this->from);

        if (! $slug) {
            return;
        }

        $feature = FeatureEnum::fromSlug($slug);

        if ($feature) {
            $this->sourceFeatureTitle = $feature->title();
            $this->sourceFeatureDescription = $feature->description();
            $this->sourceFeatureTier = $feature->minimumTier()?->label();
        }
    }

    private function sanitizeSources(array $sources): array
    {
        return array_values(array_intersect($sources, FeatureEnum::slugs()));
    }
}
