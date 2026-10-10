# Export ClickHouse Schema for CI

Export ClickHouse schemas from chlocal's cloud exports into a single `clickhouse-schema.sql` file for GitHub Actions CI testing.

## Paths

- **Zod Source:** `../chlocal/migrations/cloud_export/cloud_zod_schema.sql`
- **Clarity Source:** `../chlocal/migrations/cloud_export/cloud_clarity_schema.sql`
- **Target:** `database/schema/clickhouse-schema.sql`

## Engine Transformation Rules

Transform ClickHouse Cloud Shared* engines to standard *MergeTree engines for local/CI testing:

| Cloud Engine | CI Engine |
|--------------|-----------|
| `SharedReplacingMergeTree('/...', '{replica}', ver, del)` | `ReplacingMergeTree(ver, del)` |
| `SharedReplacingMergeTree('/...', '{replica}', ver)` | `ReplacingMergeTree(ver)` |
| `SharedReplacingMergeTree('/...', '{replica}')` | `ReplacingMergeTree()` |
| `SharedAggregatingMergeTree('/...', '{replica}')` | `AggregatingMergeTree()` |
| `SharedMergeTree('/...', '{replica}')` | `MergeTree()` |

## DEFINER Clause Removal

Remove all `DEFINER = ... SQL SECURITY DEFINER` clauses from materialized views:
- Pattern: `DEFINER = \`...\` SQL SECURITY DEFINER `
- Replace with: (empty string)

## Export Process

### Step 0: Verify chlocal Directory

Check that chlocal exists as a sibling directory:
```bash
ls -d ../chlocal 2>/dev/null
```

**Expected directory structure:**
```
parent/
├── platform/   (this repo)
└── chlocal/    (ClickHouse migrations source)
```

If chlocal is not found:
- **STOP** and inform the user
- Provide instructions to clone/locate chlocal
- Do not attempt to proceed with schema export

Then verify required subdirectories exist:
```bash
ls ../chlocal/migrations/cloud_export/cloud_zod_schema.sql
ls ../chlocal/migrations/cloud_export/cloud_clarity_schema.sql
```

### Step 1: Read Source Schemas

Read both cloud export files:
```bash
cat ../chlocal/migrations/cloud_export/cloud_zod_schema.sql
cat ../chlocal/migrations/cloud_export/cloud_clarity_schema.sql
```

### Step 2: Transform Engines

Apply regex transformations (in order from most specific to least):

1. `SharedReplacingMergeTree\('[^']+', '\{replica\}', ([^,]+), ([^)]+)\)` → `ReplacingMergeTree($1, $2)`
2. `SharedReplacingMergeTree\('[^']+', '\{replica\}', ([^)]+)\)` → `ReplacingMergeTree($1)`
3. `SharedReplacingMergeTree\('[^']+', '\{replica\}'\)` → `ReplacingMergeTree()`
4. `SharedAggregatingMergeTree\('[^']+', '\{replica\}'\)` → `AggregatingMergeTree()`
5. `SharedMergeTree\('[^']+', '\{replica\}'\)` → `MergeTree()`

### Step 3: Remove DEFINER Clauses

Remove DEFINER patterns from materialized views:
```
DEFINER = `[^`]+` SQL SECURITY DEFINER
```

### Step 4: Filter Unwanted Content

Remove from output:
- `DROP TABLE IF EXISTS` statements (CI creates fresh)
- `_peerdb_raw_mirror_*` tables (internal peerdb tables)
- Table/view comments (lines starting with `-- Table:` or `-- View:`)
- Original header/footer comments from cloud exports

### Step 4b: Manually Maintained Additions

Some tables exist in ClickHouse Cloud but are missing from chlocal's cloud export, so a
regeneration drops them. Re-add these after filtering:

| Table | Line to keep |
|-------|--------------|
| `zod.aggregate_kit_camera_name` | ``CREATE TABLE zod.aggregate_kit_camera_name (`kit_id` Int32, `camera_name` String) ENGINE = ReplacingMergeTree() ORDER BY (kit_id, camera_name) SETTINGS index_granularity = 8192;`` |

`zod.aggregate_kit_camera_name` is Cloud `SharedReplacingMergeTree('/...', '{replica}')`,
translated by the rule above. `GlKitsResource` reads it (DEV-2486), so CI fails without it.

### Step 5: Organize Output Structure

Combine into a single file with this structure:

```sql
-- ClickHouse Schema for CI Testing
-- Generated from chlocal cloud exports
-- Generated: YYYY-MM-DD HH:MM:SS

-- ===========================================
-- DATABASE SETUP
-- ===========================================
CREATE DATABASE IF NOT EXISTS clarity;
CREATE DATABASE IF NOT EXISTS zod;

-- ===========================================
-- USER DEFINED FUNCTIONS
-- ===========================================
-- (Include any UDFs like plate_variants)

-- ===========================================
-- ZOD TABLES (Source tables from PostgreSQL)
-- ===========================================
-- (Tables from cloud_zod_schema.sql)

-- ===========================================
-- CLARITY TABLES
-- ===========================================
-- (Tables from cloud_clarity_schema.sql)

-- ===========================================
-- CLARITY VIEWS
-- ===========================================
-- (Views from cloud_clarity_schema.sql)

-- ===========================================
-- CLARITY MATERIALIZED VIEWS
-- ===========================================
-- (MVs from cloud_clarity_schema.sql)
```

### Step 6: Write Output

Write the combined, transformed schema to:
```
database/schema/clickhouse-schema.sql
```

## Verification Commands

After export, verify the transformation:

```bash
# Check no Shared* engines remain
grep -c "Shared" database/schema/clickhouse-schema.sql
# Should return 0

# Count objects
echo "Tables: $(grep -c "CREATE TABLE" database/schema/clickhouse-schema.sql)"
echo "Views: $(grep -c "^CREATE VIEW" database/schema/clickhouse-schema.sql)"
echo "MVs: $(grep -c "CREATE MATERIALIZED VIEW" database/schema/clickhouse-schema.sql)"

# Check no DEFINER clauses remain
grep -c "DEFINER" database/schema/clickhouse-schema.sql
# Should return 0
```
