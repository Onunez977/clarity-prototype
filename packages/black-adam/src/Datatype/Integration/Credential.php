<?php

namespace InsightLPR\BlackAdam\Datatype\Integration;

class Credential
{
    public function __construct(public string $username, public string $password) {}
}
