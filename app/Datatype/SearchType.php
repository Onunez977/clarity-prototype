<?php

namespace App\Datatype;

enum SearchType: string
{
    case Scan = 'scan';
    case Hit = 'hit';
    case Hotlist = 'hotlist';
    case ScoutDriver = 'scout_driver';
    case Zone = 'zone';
}
