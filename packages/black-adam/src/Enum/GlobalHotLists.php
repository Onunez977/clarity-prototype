<?php

namespace InsightLPR\BlackAdam\Enum;
use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\{Group, Title, Type};
use InsightLPR\BlackAdam\Enum\Concern\IsDataType;

#[Meta(Group::class, Title::class,Type::class)]
enum GlobalHotLists :int
{
    use Metadata;
    use IsDataType;
    #[Group('NICB')]
    #[Title('Stolen Vehicles')]
    #[Type('Global')]
    case NICB_Stolen_Vehicles = 149515;


}
