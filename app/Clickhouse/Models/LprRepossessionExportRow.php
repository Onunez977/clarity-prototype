<?php

declare(strict_types=1);

namespace App\Clickhouse\Models;

use App\Clickhouse\Queries\LprRepossessionsQuery;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;

/**
 * Query-supplied export row. There is no physical table behind this model.
 * The export action sets FROM to {@see LprRepossessionsQuery::rowsSql()}
 * aliased as `lpr_repo_export`, which is also `$table` so the qualified key matches that alias.
 *
 * The key is the string triple `forwarder_org_id|agent_org_id|vin`, not a hash.
 * {@see LprRepossessionExportRowBuilder::findMany()} loads each ExportCsv chunk in
 * sub-batches so that IN list stays under ClickHouse max_query_size.
 */
#[UseEloquentBuilder(LprRepossessionExportRowBuilder::class)]
class LprRepossessionExportRow extends ClickhouseModel
{
    protected $table = 'lpr_repo_export';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;
}
