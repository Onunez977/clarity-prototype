<?php

namespace InsightLPR\BlackAdam\Enum;

use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use InsightLPR\BlackAdam\Enum\Concern\IsDataType;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;

#[Meta(Title::class)]
enum ScanMetaType: int
{
    use IsDataType;
    use Metadata;

    #[Title('Vehicle Type')]
    case VehicleType = 1;

    #[Title('Vehicle Color')]
    case VehicleColor = 2;

    #[Title('Vehicle Make')]
    case VehicleMake = 3;

    #[Title('Not Plate')]
    case MLPlateNotPlate = 4;

    #[Title('Plate State')]
    case MLPlateState = 5;

    #[Title('Formatted Address')]
    case FormattedAddress = 6;
}
