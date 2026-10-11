<?php

namespace InsightLPR\BlackAdam\Enum;

use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use ArchTech\Enums\Values;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Description;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;

#[Meta(Title::class, Description::class)]
enum HotlistOrgs: int
{
    use Metadata;
    use Values;

    #[Title('OLD')]
    case NCIC_ID = 384182;
    #[Title('Florida')]
    case FL_DPS_ID = 558699;
    #[Title('Louisiana')]
    case LA_DPS_ID = 458451;
    #[Title('Michigan')]
    case MI_DPS_ID = 558710;
    #[Title('Minnesota')]
    case MN_DPS_ID = 558709;
    #[Title('Mississippi')]
    case MS_DPS_ID = 451465;
    #[Title('Washington')]
    case WA_DPS_ID = 558711;
    #[Title('Iowa')]
    case IA_DPS_ID = 591332;
    #[Title('Texas')]
    case TX_DPS_ID = 640642;
}
