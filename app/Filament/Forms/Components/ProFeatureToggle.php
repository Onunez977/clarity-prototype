<?php

namespace App\Filament\Forms\Components;

use Filament\Actions\Action;
use Filament\Forms;
use Laravel\Pennant\Feature;

class ProFeatureToggle extends Forms\Components\Toggle
{
    protected string $view = 'forms.components.pro-feature-toggle';

    protected string $proFeature = '';

    protected string $proDescription = '';

    public function proFeature(string $featureName): static
    {
        $this->proFeature = $featureName;

        return $this;
    }

    public function getProFeature(): string
    {
        return $this->proFeature;
    }

    public function proDescription(string $description): static
    {
        $this->proDescription = $description;

        return $this;
    }

    public function getProDescription(): string
    {
        return $this->proDescription;
    }

    public function isProInactive(): bool
    {
        return Feature::inactive($this->proFeature) && session()->missing('admin_impersonator');
    }

    public function hintAction(Action|\Closure $action): static
    {
        $this->hintActions = [];

        return parent::hintAction($action);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->disabled(fn () => $this->isProInactive());

        $this->hint('Pro');

        $this->hintColor('primary');

        $this->hintIcon(fn () => ! $this->isProInactive()
            ? 'heroicon-o-information-circle'
            : false
        );

        $this->hintIconTooltip(fn () => $this->proDescription);

        $this->hintAction(
            Action::make('upgrade')
                ->hiddenLabel()
                ->icon('heroicon-o-lock-closed')
                ->color('gray')
                ->size('lg')
                ->tooltip(fn () => $this->proDescription)
                ->actionJs("\$dispatch('open-modal', { id: 'pro-modal' })")
                ->visible(fn () => $this->isProInactive())
        );
    }
}
