<?php

namespace InsightLPR\BlackAdam\Enum;

use ArchTech\Enums\Metadata;;

use ArchTech\Enums\Names;
use ArchTech\Enums\Options;
use ArchTech\Enums\Values;
use InsightLPR\BlackAdam\Enum\Concern\EnumHasValues;

use ArchTech\Enums\Meta\Meta;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\{Title};

#[Meta(Title::class)]

enum FleetUserType : int
{
    use Metadata;
    use Values;
    use Options;
    use Names;

    #[Title('Unknown')]
    case Unknown = 9;
    #[Title('Repossessor')]
    case Repo = 1;
    #[Title('Forwarder')]
    case Forwarder = 2;

    public static function safeTryFrom(mixed $value = null): FleetUserType
    {
        try {
            return self::tryFrom($value) ?? self::Unknown;
        } catch (\Throwable $th) {
            return self::Unknown;
        }
    }

    public static function toSelectOptions(): array
    {
        return collect(FleetUserType::options())
            ->reject(fn($type) => $type === FleetUserType::Unknown->value)
            ->flip()
            ->mapWithKeys(function($type,$key){
                return [$key=> FleetUserType::safeTryFrom($key)->title()];
            })
            ->toArray();
    }
}
