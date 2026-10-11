<?php

namespace InsightLPR\BlackAdam\Enum\Concern;

use Illuminate\Support\Collection;
use InsightLPR\BlackAdam\Enum\Datatype\EnumCollectionItem;

trait EnumHasValues
{
    public static function getValues(bool $humanized = false): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn ($case) => [$case->name => $case->value])
            ->toArray();
    }

    /**
     * @return Collection<EnumCollectionItem>
     */
    public static function asCollection(): Collection
    {
        return collect(self::cases())
            ->map(fn ($case) => new EnumCollectionItem(
                value: $case->value,
                name: $case->name,
                human: method_exists(self::class, 'toHuman') ? $case->toHuman() : $case->name
            ));
    }
}
