<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ScansResource\Pages;
use App\Models\Scan;
use Filament\Resources\Resource;

class ScansResource extends Resource
{
    protected static ?string $model = Scan::class;

    protected static ?int $navigationSort = 1;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-camera';

    protected static string|\UnitEnum|null $navigationGroup = 'Search';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListScans::route('/'),
        ];
    }
}
