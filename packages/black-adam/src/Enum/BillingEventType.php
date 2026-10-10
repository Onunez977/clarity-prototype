<?php

namespace InsightLPR\BlackAdam\Enum;

enum BillingEventType :string
{
    case VIN2PLATE = 'v2p';
    case CASE_CLAIM = 'case-claim';

    case LS_CASE_CLAIM = 'ls-claim';

    case INS_LOOKUP = 'ins-lookup';
    case INS_PEEK = 'ins-peek';
    case SMARTASS = 'smartass';
    
    case CLARITY_PRO = 'claritypro';

    public function getEventName(): string
    {
        return match ($this) {
            self::VIN2PLATE => 'Vin2Plate',
            self::CASE_CLAIM => 'Case Claim',
            self::LS_CASE_CLAIM => 'LS Case Claim',
            self::INS_LOOKUP => 'Ins Lookup',
            self::INS_PEEK => 'Ins Peek',
            self::SMARTASS => 'Smartass',
            self::CLARITY_PRO => 'Clarity Pro',
        };
    }

    public static function getEventTypes(): array
    {
        return [
            self::VIN2PLATE->value => self::VIN2PLATE->getEventName(),
            self::CASE_CLAIM->value => self::CASE_CLAIM->getEventName(),
            self::LS_CASE_CLAIM->value => self::LS_CASE_CLAIM->getEventName(),
            self::INS_LOOKUP->value => self::INS_LOOKUP->getEventName(),
            self::INS_PEEK->value => self::INS_PEEK->getEventName(),
            self::SMARTASS->value => self::SMARTASS->getEventName(),
            self::CLARITY_PRO->value => self::CLARITY_PRO->getEventName(),
        ];
    }

}
