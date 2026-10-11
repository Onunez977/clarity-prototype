<?php

declare(strict_types=1);

namespace App\Enums;

use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use InsightLPR\BlackAdam\Enum\Concern\EnumHasValues;
use InsightLPR\BlackAdam\Enum\Concern\IsDataType;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;

#[Meta(Title::class)]
enum VehicleTypeEnum: string
{
    use EnumHasValues;
    use IsDataType;
    use Metadata;

    #[Title('Scanning Car')]
    case ScanningCar = 'scanning_car';

    #[Title('Repo Truck')]
    case RepoTruck = 'repo_truck';

    #[Title('Flatbed')]
    case Flatbed = 'flatbed';

    public static function formOptions(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->title()])
            ->toArray();
    }
}
