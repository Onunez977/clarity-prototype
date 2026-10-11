<?php

namespace App\Support\Filament;

use Filament\Facades\Filament;

trait FilamentRouteRedirect
{
    public function getFirstAvailableRoute(): ?string
    {
        return collect(Filament::getCurrentOrDefaultPanel()->getNavigation())
            ->flatten()
            ->map(fn ($group) => $group->getItems())
            ->flatten()
            ->filter(fn ($item) => ! \in_array($item->getLabel(), ['Platform V1', 'Billing', 'Profile', 'Client Dashboard']))
            ->first()
            ?->getUrl();
    }
}
