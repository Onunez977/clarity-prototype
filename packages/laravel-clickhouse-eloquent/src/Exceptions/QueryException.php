<?php

namespace LaravelClickhouseEloquent\Exceptions;

class QueryException extends ClickhouseException
{

    public static function cannotUpdateEmptyValues(): self
    {
        return new self('Error updating empty values');
    }

}
