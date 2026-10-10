<?php

declare(strict_types=1);

namespace InsightLPR\BlackAdam\Enum;

use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\Concern\EnumHasValues;
use InsightLPR\BlackAdam\Enum\Concern\IsDataType;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;

#[Meta(Title::class)]
enum RoleEnum:string
{
    use Metadata;
    use IsDataType;
    use EnumHasValues;

    #[Title('Administrator')]
    case ADMIN = 'Administrator';
    #[Title('Default User')]
    case USER = 'Default User';
    #[Title('Client')]
    case CLIENT = 'Client';

    public static function isProtected(array|string $value): bool
    {
        $value = Arr::wrap($value);
        $protected  = false;

        $roles = array_change_key_case(array_flip(self::getValues()));
        foreach ($value as $v) {
            isset($roles[Str::lower($v)]) && $protected = true;
        }
        return $protected;
    }

    public function permissions(){
        $administrator_roles = [
            PermissionEnum::MANAGE_ACCOUNT_ROLES->value,
            PermissionEnum::MANAGE_ACCOUNT_USERS->value,
            PermissionEnum::VIEW_ACCOUNT_ACTIVITY->value
        ];

        return match($this->value) {
            RoleEnum::ADMIN->value => $administrator_roles,
            RoleEnum::USER->value => PermissionEnum::asCollection()
                ->reject(
                    fn($permission)=>in_array($permission->value,$administrator_roles)
                )
            ->pluck('value')->toArray()
        };
    }
}
