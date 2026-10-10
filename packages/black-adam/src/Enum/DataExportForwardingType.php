<?php

namespace InsightLPR\BlackAdam\Enum;

use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use InsightLPR\BlackAdam\Enum\Concern\IsDataType;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;

#[Meta(Title::class)]
enum DataExportForwardingType:int
{
    use Metadata;
    use IsDataType;
    #[Title('HTTP')]
    case HTTP = 0;
    #[Title('SFTP')]
    case SFTP = 1;

    #[Title('Axon')]
    case Axon = 2;
}
