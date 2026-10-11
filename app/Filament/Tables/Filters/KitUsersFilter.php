<?php

namespace App\Filament\Tables\Filters;

use Filament\Actions\Action;
use Filament\Forms;
use Filament\Schemas\Components\Utilities\Set;

class KitUsersFilter extends Forms\Components\Select
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->options = auth()->user()->getUserKits()->pluck('name', 'id');

        $this->placeholder = 'Select Kit Users';

        $this->optionsLimit = 999;

        $this->columnSpan('full');

        $this->hintActions = [
            Action::make('selectAll')
                ->icon('heroicon-o-rectangle-stack')
                ->visible(fn ($state): bool => ! isset($state[0]) || $state[0] !== 'All Kits')
                ->action(fn (Set $set): mixed => $set($this->getName(), ['All Kits'])),

            Action::make('unselect')
                ->icon('heroicon-m-x-mark')
                ->color('warning')
                ->visible(fn ($state): bool => isset($state[0]))
                ->action(fn (Set $set): mixed => $set($this->getName(), [])),
        ];
    }

    public static function format(array $kits): mixed
    {
        $userKits = auth()->user()->getUserKits()->pluck('id');

        if (isset($kits[0]) && $kits[0] === 'All Kits') {
            return $userKits->toArray();
        }

        // Get all the given Kits that exist in the user's kits.
        return collect($kits)
            ->intersect($userKits->toArray())
            ->values()
            ->toArray();
    }

    public static function indicator(array $data): string
    {
        if ($data[0] === 'All Kits') {
            return 'All Kit Users';
        } else {
            $count = \count($data);
            if ($count > 10) {
                $count = '10+';
            }

            return 'Kit Users ('.$count.')';
        }
    }
}
