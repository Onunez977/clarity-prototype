<?php

namespace App\Dto;

use Spatie\LaravelData\Data;

/**
 * WARNING - THIS IS A PARTIAL PROJECTION OF hotlist_json.json, AND IT IS LOSSY ON WRITE.
 *
 * The stored blob is externally owned and carries ~21 keys; this DTO declares only
 * branch_id. Reading through it is fine. Serializing it back (which is what the `json`
 * cast on App\Models\HotlistJson does on every write) DROPS every key not declared here.
 * Production writes must not go through it - use targeted jsonb_set SQL.
 */
class HotlistJsonData extends Data
{
    public function __construct(
        public ?int $branch_id = null,
    ) {}
}
