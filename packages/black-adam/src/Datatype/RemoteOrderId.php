<?php

namespace InsightLPR\BlackAdam\Datatype;

class RemoteOrderId
{
    public function __construct(
        public readonly string|int|null $id
    ) {}

    public function __toString(): string
    {
        return (string) $this->id;
    }
}
