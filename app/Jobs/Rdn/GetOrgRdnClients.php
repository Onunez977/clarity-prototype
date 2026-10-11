<?php

namespace App\Jobs\Rdn;

use App\Dto\Rdn\ClientDto;
use App\Models\Org;
use App\Services\RDN;

class GetOrgRdnClients extends RdnJob
{
    public static function fetch(RDN $rdn, Org $org): void
    {
        $rdn
            ->getClientList()
            ->each(function (ClientDto $client) use ($org) {
                if ($clientOrg = Org::where('rdn_id', $client->client_id)->first()) {
                    $properties = [
                        'rdn_client_type' => \strtolower($client->type),
                    ];

                    if (\strtolower($client->type) === 'local') {
                        $properties['rdn_client_belongs_to'] = $org->id;
                    }

                    $clientOrg->meta ?
                        $clientOrg->meta->updateProperties($properties) :
                        $clientOrg->meta()->create([
                            'properties' => $properties,
                        ]);
                }
            });
    }
}
