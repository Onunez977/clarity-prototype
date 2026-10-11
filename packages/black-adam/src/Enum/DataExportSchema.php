<?php

namespace InsightLPR\BlackAdam\Enum;

use ArchTech\Enums\Metadata;;

use ArchTech\Enums\Meta\Meta;
use InsightLPR\BlackAdam\Enum\Concern\IsDataType;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\{Title};

#[Meta(Title::class)]
enum DataExportSchema:int
{
    use Metadata;
    use IsDataType;

    #[Title('InsightLPR Default')]
    case Default = 0;
    #[Title('Fusus')]
    case Fusus = 1;
    #[Title('Motorola')]
    case Motorola = 2;
    #[Title('Insight Commercial Data')]
    case InsightCommercialData = 3;
    #[Title('Finder')]
    case Finder = 4;
    #[Title('Strax')]
    case Strax = 5;
    #[Title('TxDPS')]
    case TxDPS = 6;
    #[Title('XML')]
    case XML = 7;
    #[Title('Peregrine')]
    case Peregrine = 8;
    #[Title('Axon')]
    case Axon = 9;

    #[Title('Leosight')]
    case Leosight = 10;
}
