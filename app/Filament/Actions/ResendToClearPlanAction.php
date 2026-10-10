<?php

namespace App\Filament\Actions;

use App\Enums\FeatureEnum;
use App\Enums\HotlistStatusEnum;
use App\Facades\Analytics;
use App\Jobs\ResendHitsToClearPlanJob;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Blade;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;

class ResendToClearPlanAction extends BulkAction
{
    protected string|Htmlable|Closure|null $label = 'Resend to ClearPlan';

    protected Closure|string|Htmlable|null|false|\BackedEnum $icon = 'heroicon-m-arrow-path';

    protected bool|Closure $shouldDeselectRecordsAfterCompletion = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->color('warning');

        $this->disabled(function (): bool {
            $user = auth()->user();
            $isPro = Feature::active(FeatureEnum::CLEARPLAN_RESEND->slug()) || session()->has('admin_impersonator');
            $isAdmin = $user->hasRole(RoleEnum::ADMIN->value);

            return ! $isPro || ! $isAdmin;
        });

        $this->tooltip(function (): ?string {
            $user = auth()->user();
            $isPro = Feature::active(FeatureEnum::CLEARPLAN_RESEND->slug()) || session()->has('admin_impersonator');
            $isAdmin = $user->hasRole(RoleEnum::ADMIN->value);

            if (! $isPro) {
                return 'Upgrade to Pro to resend hits to ClearPlan';
            }

            if (! $isAdmin) {
                return 'Admin only';
            }

            return null;
        });

        $this->modalHeading('Resend to ClearPlan');

        $this->modalDescription(function (Collection $records): string {
            $openCount = $records->where('hotlist_status', HotlistStatusEnum::Open->value)->count();
            $skippedCount = $records->count() - $openCount;

            $description = "{$openCount} Open hit(s) will be resent to ClearPlan.";

            if ($skippedCount > 0) {
                $description .= " {$skippedCount} non-Open hit(s) will be skipped.";
            }

            return $description;
        });

        $this->modalSubmitActionLabel('Resend');

        $this->action(function (Collection $records): void {
            if (! Feature::active(FeatureEnum::CLEARPLAN_RESEND->slug()) && ! session()->has('admin_impersonator')) {
                Notification::make()
                    ->title('This is a Pro feature')
                    ->body('Upgrade to Pro to resend hits to ClearPlan.')
                    ->warning()
                    ->send();

                return;
            }

            $openHitIds = $records
                ->where('hotlist_status', HotlistStatusEnum::Open->value)
                ->pluck('id')
                ->all();

            if ($openHitIds === []) {
                Notification::make()
                    ->title('No Open hits selected')
                    ->body('Only hits with an Open status can be resent to ClearPlan.')
                    ->warning()
                    ->send();

                return;
            }

            ResendHitsToClearPlanJob::dispatch($openHitIds, auth()->user());

            Analytics::track('resend-to-clearplan', auth()->user(), [
                'action' => 'pro-feature',
                'hit_count' => count($openHitIds),
            ], page: 'hits');

            Notification::make()
                ->title(count($openHitIds).' hit(s) queued for resend to ClearPlan')
                ->success()
                ->send();
        });
    }

    public function toHtml(): string
    {
        if (! config('app.experimental.clearplan', false) && session()->missing('admin_impersonator')) {
            return '';
        }

        $html = parent::toHtml();

        $isPro = Feature::active(FeatureEnum::CLEARPLAN_RESEND->slug()) || session()->has('admin_impersonator');

        if (! $isPro) {
            $html = Blade::render(
                '<x-pro-upgrade-popover :active="false" description="Resend hits to ClearPlan with a Pro subscription." feature="clearplan-resend">{!! $slot !!}</x-pro-upgrade-popover>',
                ['slot' => $html],
            );
        }

        return $html;
    }
}
