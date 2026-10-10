<?php

declare(strict_types=1);

namespace App\Clickhouse\Queries;

/**
 * Shared dedup triple for the claims and repossessions funnels (DEV-2659).
 *
 * Static SQL only. Call sites name the aggregate. This class returns the
 * triple and the repos CTE text.
 * `forwarder_org_id` stays in the key. The repos `deduped` CTE is the one
 * materialization that needs `min(claim_date)` for the hotlist join.
 */
class BillingClaimDedup
{
    public static function tripleSql(): string
    {
        return '(forwarder_org_id, agent_org_id, vin)';
    }

    public static function dedupedCteSql(): string
    {
        return "SELECT bucket, forwarder_org_id, agent_org_id, vin, min(claim_date) AS claim_date FROM claims WHERE success AND vin != '' GROUP BY bucket, forwarder_org_id, agent_org_id, vin";
    }
}
