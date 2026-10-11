<?php

namespace InsightLPR\BlackAdam\Enum\Datatype;

class EnumCollectionItem
{
    public function __construct(
        public readonly int|string $value,
        public readonly int|string $name,
        public readonly int|string $human)
    {

    }
}
