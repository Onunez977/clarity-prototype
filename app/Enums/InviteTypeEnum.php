<?php

declare(strict_types=1);

namespace App\Enums;

use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use InsightLPR\BlackAdam\Enum\Concern\EnumHasValues;
use InsightLPR\BlackAdam\Enum\Concern\IsDataType;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Description;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;

#[Meta(Title::class, Description::class)]
enum InviteTypeEnum: string
{
    use EnumHasValues;
    use IsDataType;
    use Metadata;

    #[Title('Share Scans')]
    #[Description('View and Share Scans from a different Company.')]
    case SHARE_SCANS = 'Share Scans';

    #[Title('Client Scans')]
    #[Description('Able to search through scans through the client portal.')]
    case CLIENT_SCANS = 'Client Scans';
}
