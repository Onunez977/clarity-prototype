<?php

namespace InsightLPR\BlackAdam\Enum;

use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use InsightLPR\BlackAdam\Enum\Concern\IsDataType;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;

#[Meta(Title::class)]
enum DataExportDataset:int
{
    use Metadata;
    use IsDataType;

    #[Title('Scans')]
    case Scans = 0;
    #[Title('Hits')]
    case Hits = 1;
}
