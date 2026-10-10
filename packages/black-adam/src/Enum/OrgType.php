<?php

namespace InsightLPR\BlackAdam\Enum;

use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use InsightLPR\BlackAdam\Enum\Concern\InteractsWithHumans;
use InsightLPR\BlackAdam\Enum\Concern\IsDataType;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;

#[Meta(Title::class)]
enum OrgType :int implements InteractsWithHumans
{
    use Metadata;
    use Concern\EnumHasValues;
    use IsDataType;
    #[Title('Repo Company')]
    case Repo = 1;
    #[Title('Repo Client')]
    case Client = 2;
    #[Title('Lien Holder')]
    case LienHolder = 3;
    #[Title('Insurance')]
    case Insurance = 5;
    #[Title('Real Estate')]
    case RealEstate = 6;
    #[Title('Law Enforcement')]
    case LawEnforcement = 4;
    #[Title('Retailer')]
    case Retailer = 7;

    #[Title('Insight')]
    case Other = 99;

    public function toHuman(): string
    {
        return $this->title();
    }
}
