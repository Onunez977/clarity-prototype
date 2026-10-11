<?php

namespace InsightLPR\BlackAdam\Enum;

use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use InsightLPR\BlackAdam\Enum\Concern\IsDataType;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Group;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;

#[Meta(Group::class, Title::class)]
enum StagingOrgs :int
{
    use Metadata;
    use IsDataType;

    case Location_Services_LPR = 165;
    case Location_Services_LLC = 851;
    case Location_Services_USA = 6523;
    case Primeritus_Financial_Services = 160;
    case Lenders_And_Fleet_Services_Inc  = 3791;
    case Millennium_Capital_and_Recovery_Corporation = 81;
    case Millennium_Capital_Skip_Recovery_Corp = 2137;
    case Bell_Williams = 1903;
    case Burns_National = 355;
    case Victory_Recovery_Services = 1337;
    case American_Recovery_Service = 170;

}
