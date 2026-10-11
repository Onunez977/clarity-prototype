<?php

namespace App\Jobs\Rdn;

use App\Dto\Rdn\AgentDto;
use App\Models\Org;
use App\Services\RDN;

class GetOrgAgents extends RdnJob
{
    public static function fetch(RDN $rdn, Org $org): void
    {
        $agents = [];

        foreach (['Company Adjuster', 'Outside Adjuster'] as $type) {
            $rdn
                ->getAgents($type)
                ->each(function (AgentDto $agent) use (&$agents, $type) {
                    $agents[$agent->agent_id] = [
                        'first_name' => $agent->first_name,
                        'last_name' => $agent->last_name,
                        'type' => $type,
                        'vendor_name' => $agent->vendor_name,
                        'vendor_phone' => $agent->vendor_phone,
                    ];
                });
        }

        if (! empty($agents)) {
            $org->meta ?
                $org->meta->updateProperties(['rdn_agents' => $agents]) :
                $org->meta()->create([
                    'properties' => ['rdn_agents' => $agents],
                ]);
        }
    }
}
