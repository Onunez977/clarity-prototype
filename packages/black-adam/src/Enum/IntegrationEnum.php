<?php

declare(strict_types=1);

namespace InsightLPR\BlackAdam\Enum;

use InsightLPR\BlackAdam\Datatype\RemoteOrderId;

enum IntegrationEnum : Int
{
    case RDN = 1;
    case Location_Services = 2;
    case iRepo = 3;
    case NICB = 5;
    case Primeritus = 6;
    case Manual_Upload = 7;

    public function remoteLink(RemoteOrderId $remoteOrderId): ?string
    {
        if(empty($remoteOrderId)){
            return null;
        }
        return match($this){
            self::RDN => sprintf('https://app.recoverydatabase.net/alpha_rdn/module/default/case2/?case_id=%s', $remoteOrderId),
            default => null
        };
    }
}
