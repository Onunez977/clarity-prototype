<?php

declare(strict_types=1);

namespace App\Enums;

use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use InsightLPR\BlackAdam\Enum\Concern\EnumHasValues;
use InsightLPR\BlackAdam\Enum\Concern\IsDataType;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;

#[Meta(Title::class)]
enum DispositionEnum: string
{
    use EnumHasValues;
    use IsDataType;
    use Metadata;

    #[Title('New Hit')]
    case NEW_HIT = 'New Hit';

    #[Title('Accessible')]
    case ACCESSIBLE = 'Accessible';

    #[Title('Moving')]
    case MOVING = 'Moving';

    #[Title('Inaccessible')]
    case INACCESSIBLE = 'Inaccessible';

    #[Title('Invalid Scan')]
    case INVALID_SCAN = 'Invalid Scan';

    #[Title('Valid')]
    case VALID = 'Valid';

    #[Title('Invalid')]
    case INVALID = 'Invalid';

    #[Title('Reviewed')]
    case REVIEWED = 'Reviewed';

    public static function formOptions(bool $missedOpportunity = false): array
    {
        $missedOpportunityEnums = [
            self::VALID->value,
            self::REVIEWED->value,
            self::INVALID->value,
        ];

        return collect(self::values())
            ->filter(function ($value) use ($missedOpportunity, $missedOpportunityEnums) {
                return $missedOpportunity
                    ? \in_array($value, $missedOpportunityEnums)
                    : ! \in_array($value, $missedOpportunityEnums);
            })
            ->mapWithKeys(fn ($value) => [$value => self::tryFrom($value)->title()])
            ->sort()
            ->toArray();
    }
}
