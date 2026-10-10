# Dev Tools

Scripts for syncing schema from ClickHouse Cloud to local Docker environment.

## Schema Exporter

Connects to ClickHouse Cloud and generates SQL migration files that can be run against the local Docker ClickHouse instance.

### Setup

```bash
cd dev
cp .env.example .env
```

Edit `.env` with your ClickHouse Cloud credentials:

```
CLICKHOUSE_CLOUD_HOST=your-instance.clickhouse.cloud
CLICKHOUSE_CLOUD_PORT=8443
CLICKHOUSE_CLOUD_USER=default
CLICKHOUSE_CLOUD_PASSWORD=your-password
CLICKHOUSE_CLOUD_SECURE=true
CLICKHOUSE_DATABASES=zod,clarity
```

Install dependencies:

```bash
pip install -r requirements.txt
```

### Usage

```bash
python export_schema.py
```

This will:
1. Connect to ClickHouse Cloud
2. Query schema for each database in `CLICKHOUSE_DATABASES`
3. Generate SQL files in `../migrations/cloud_export/`

### Output

Files are created at `migrations/cloud_export/cloud_<database>_schema.sql`

Each file contains:
- `CREATE DATABASE` statement
- Tables (ordered first for dependencies)
- Views
- Materialized Views (ordered last)

### Applying to Local Docker

After exporting, apply to your local ClickHouse:

```bash
# Make sure Docker is running
docker compose up -d

# Apply a specific database schema
docker exec -i clickhouse-server clickhouse-client --multiquery < ../migrations/cloud_export/cloud_zod_schema.sql

# Or apply all exported schemas
for f in ../migrations/cloud_export/*.sql; do
  echo "Applying $f..."
  docker exec -i clickhouse-server clickhouse-client --multiquery < "$f"
done
```

### Notes

- UUIDs and `ON CLUSTER` clauses are automatically removed from exported SQL
- Tables are ordered before views/materialized views to handle dependencies
- System tables (`altinity_*`, `replica_*`) are excluded from export
- Exported files are gitignored to avoid committing production schema
