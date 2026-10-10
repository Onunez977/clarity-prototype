<?php

namespace InsightLPR\BlackAdam\Enum;

use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;
use InsightLPR\BlackAdam\Enum\Concern\IsDataType;

#[Meta(Title::class)]
enum KitType :int
{
    use Metadata;
    use IsDataType;

    #[Title('Genetec Mobile')]
    case GENETEC = 1;
    #[Title('Matrix Edge')]
    case MATRIX  = 2;
    #[Title('Genetec Cloudrunner')]
    case GENETEC_CLOUDRUNNER = 3;
    #[Title('Defender Q')]
    case DEFENDER_Q = 4;
    #[Title('UP 4REPO')]
    case UP4REPO = 5;
    #[Title('Axis Camera')]
    case AXIS = 6;
    #[Title('PIPS')]
    case PIPS = 7;
    #[Title('MX Defender Solar')]
    case MX_DEFENDER_SOLAR  = 9;
    #[Title('VAXTOR')]
    case VAXTOR = 10;
    #[Title('Milesight PTZ')]
    case MILESIGHT_PTZ = 11;
    #[Title('Uniview')]
    case UNIVIEW = 12;
}
