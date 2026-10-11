<?php

declare(strict_types=1);

namespace App\Enums;

use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use InsightLPR\BlackAdam\Enum\Concern\EnumHasValues;
use InsightLPR\BlackAdam\Enum\Concern\IsDataType;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;

#[Meta(Title::class)]
enum ShiftEndTypeEnum: string
{
    use EnumHasValues;
    use IsDataType;
    use Metadata;

    #[Title('Confirmed')]
    case Confirmed = 'confirmed';

    #[Title('Assumed')]
    case Assumed = 'assumed';
}
