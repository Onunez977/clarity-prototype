<?php

declare(strict_types=1);

namespace App\Enums;

use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use InsightLPR\BlackAdam\Enum\Concern\EnumHasValues;
use InsightLPR\BlackAdam\Enum\Concern\IsDataType;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;

#[Meta(Title::class)]
enum HitTypeEnum: string
{
    use EnumHasValues;
    use IsDataType;
    use Metadata;

    #[Title('Live')]
    case Live = 'L';

    #[Title('Historical')]
    case Historical = 'H';

    #[Title('Staging Live')]
    case Staging_Live = 'S';

    #[Title('Staging Historical')]
    case Staging_Historical = 'Z';

    #[Title('Missed Opportunity')]
    case Missed_Opportunity = 'M';

    #[Title('Missed Opportunity Spotted & Closed')]
    case Missed_Opportunity_Historical = 'F';

    #[Title('Probable Match')]
    case Probable_Match = 'P';
}
