<?php

declare(strict_types=1);

namespace App\Services\Zones;

use Illuminate\Support\Facades\DB;
use Throwable;

class ZipBoundaryLookup
{
    /**
     * ZIP codes with no usable Census boundary. Read on the `platform` connection —
     * the same one Zone::rebuildGeometryFromDefinition() uses — so the answer matches
     * the rows that actually built the geometry. `the_geom` is nullable, and a row whose
     * geometry is NULL contributes nothing to the union, so row existence alone is
     * not the question. Degrades to an empty list rather than throwing: a failure
     * here must not mask the real error. Call OUTSIDE the save transaction.
     *
     * @param  list<mixed>  $zipCodes
     * @return list<string>
     */
    public function unresolved(array $zipCodes): array
    {
        $zipCodes = array_values(array_unique(array_map('strval', $zipCodes)));

        if ($zipCodes === []) {
            return [];
        }

        try {
            $matched = DB::connection('platform')
                ->table('tiger_data.zcta5_all')
                ->whereIn('zcta5ce', $zipCodes)
                ->whereNotNull('the_geom')
                ->distinct()
                ->pluck('zcta5ce')
                ->all();
        } catch (Throwable) {
            return [];
        }

        return array_values(array_diff($zipCodes, array_map('strval', $matched)));
    }
}
