<?php

namespace App\Jobs\Rdn;

use App\Models\Branch;
use App\Models\Org;
use App\Services\RDN;

class GetOrgBranches extends RdnJob
{
    public static function fetch(RDN $rdn, Org $org): void
    {
        $rdn->toLazyCollection(
            method: 'getBranchList',
            format: function ($item) use ($org) {
                if (\gettype($item) === 'object' || \gettype($item) === 'array') {
                    $item = \gettype($item) === 'array' ? (object) $item : $item;

                    if (! property_exists($item, 'branch_id')) {
                        return;
                    }

                    Branch::updateOrCreate(
                        [
                            'org_id' => $org->id,
                            'branch_id' => $item->branch_id,
                        ],
                        [
                            'udbsaid' => $item->udbsaid,
                            'branch_name' => $item->branch_name,
                            'phone' => $item->phone,
                            'address' => $item->address,
                            'city' => $item->city,
                            'state' => $item->state,
                            'zip_code' => $item->zip_code,
                            'fax' => $item->fax,
                        ]
                    );
                }
            }
        )->all();
    }
}
