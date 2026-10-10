<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use InsightLPR\BlackAdam\Enum\RoleEnum;

/**
 * @codeCoverageIgnore
 */
class RecentChanges extends Page
{
    protected static bool $shouldRegisterNavigation = true;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Recent Changes';

    protected static string|null|\UnitEnum $navigationGroup = 'Help';

    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.pages.changelog';

    public function getHeading(): string|Htmlable
    {
        return 'Recent Changes';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Stay updated with the latest features and improvements';
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasAnyRole(RoleEnum::ADMIN->value, RoleEnum::USER->value);
    }
}
