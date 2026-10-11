<?php

namespace App\Jobs\Rdn;

use App\Models\Branch;
use App\Models\Org;
use App\Services\RDN;

class GetOrgBranchZips extends RdnJob
{
    public static function fetch(RDN $rdn, Org $org): void
    {
        Branch::where('org_id', $org->id)
            ->whereNotNull('branch_id')
            ->cursor()
            ->each(function (Branch $branch) use ($rdn) {
                $zips = $rdn->getBranchZips($branch->branch_id);

                if (! empty($zips)) {
                    $branch->update([
                        'zip_code' => implode(',', $zips),
                    ]);
                }
            });
    }
}
