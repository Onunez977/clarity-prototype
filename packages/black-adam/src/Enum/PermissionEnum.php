<?php

declare(strict_types=1);

namespace InsightLPR\BlackAdam\Enum;

use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use InsightLPR\BlackAdam\Enum\Concern\EnumHasValues;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Description;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Group;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;

#[Meta(Description::class, Group::class, Title::class)]
enum PermissionEnum: string
{
    use EnumHasValues;
    use Metadata;

    #[Description('Manage Account Users')]
    #[Group('Account')]
    #[Title('Manage Account Users')]
    case MANAGE_ACCOUNT_USERS = 'manage-account-users';

    #[Group('Account')]
    #[Title('Manage Account Roles')]
    #[Description('Update, delete, and create roles for your account.')]
    case MANAGE_ACCOUNT_ROLES = 'manage-account-roles';

    #[Group('Account')]
    #[Title('Account Activity')]
    #[Description('User can view and search account activity')]
    case VIEW_ACCOUNT_ACTIVITY = 'view-account-activity';

    #[Group('Scans')]
    #[Title('View Scans')]
    #[Description('View Scans')]
    case ViewAny_SCAN = 'viewany-scan';

    #[Group('Scans')]
    #[Title('No Location Data')]
    #[Description('Allows user to view scans but not location data.')]
    case NoLocationData_SCAN = 'no-location-data-scan';

    #[Group('Scans')]
    #[Title('Disposition Scan')]
    #[Description('User can disposition a scan')]
    case Disposition_Scan = 'disposition-scan';

    #[Group('Hits')]
    #[Title('View Hits')]
    #[Description('User can access Hits')]
    case ViewAny_Hit = 'viewany-hit';

    #[Group('Hotlist')]
    #[Title('View Hotlist')]
    #[Description('View the Hotlist')]
    case ViewAny_Hotlist = 'viewany-hotlist';

    #[Group('Reports')]
    #[Title('Affiliate Stats')]
    #[Description('View the Affiliate Stats Report')]
    case Reports_View_Affiliate_Stats = 'reports:view_affiliate_stats';

    #[Group('Reports')]
    #[Title('Scan Report')]
    #[Description('View the Scan Report')]
    case Reports_View_Scan_Report = 'reports:view_scan_report';

    #[Group('Reports')]
    #[Title('Kit User Recoveries Report')]
    #[Description('View the Kit User Recoveries Report')]
    case Reports_View_Kit_User_Recoveries_Report = 'reports:view_kit_user_recoveries_report';

    #[Group('Reports')]
    #[Title('GeoSpatial Kit User Path')]
    #[Description('User can access the GeoSpatial Kit User Path')]
    case Analytics_Geospatial_Kit_User_Path = 'analytics:geospatial:kit_user_path';

    #[Group('Account')]
    #[Title('Account Billing')]
    #[Description('User can access Account Billing')]
    case Account_Billing = 'account:billing';
}
