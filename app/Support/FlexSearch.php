<?php

namespace App\Support;

use App\Enums\FeatureEnum;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

final class FlexSearch
{
    public static function isAvailable(): bool
    {
        return Feature::active(FeatureEnum::FLEX_SEARCH->slug())
            || session()->has('admin_impersonator');
    }

    public static function isCloseMatch(?string $searchedPlate, ?string $rowPlate, bool $flexEnabled): bool
    {
        if (! $flexEnabled || blank($searchedPlate) || blank($rowPlate)) {
            return false;
        }

        return Str::upper(trim($rowPlate)) !== Str::upper(trim($searchedPlate));
    }
}
