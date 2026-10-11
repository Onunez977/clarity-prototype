# ClickHouse Schema Explorer

Explore and document ClickHouse database schemas using credentials from `dev/.env`.

## Connection

```bash
# Load from dev/.env
HOST=$CLICKHOUSE_CLOUD_HOST
PORT=$CLICKHOUSE_CLOUD_PORT
USER=$CLICKHOUSE_CLOUD_USER
PASSWORD=$CLICKHOUSE_CLOUD_PASSWORD
```

## Query Helper

```bash
curl -s "https://${HOST}:${PORT}" \
  --user "${USER}:${PASSWORD}" \
  --data "${QUERY} FORMAT TabSeparatedWithNames"
```

## Common Schema Queries

### List All Tables with Details
```sql
SELECT 
    database,
    name as table,
    engine,
    formatReadableSize(total_bytes) as size,
    formatReadableQuantity(total_rows) as rows,
    partition_key,
    sorting_key
FROM system.tables 
WHERE database IN ('zod', 'clarity', 'overwatch')
ORDER BY database, name
```

### Table Column Details
```sql
SELECT 
    name,
    type,
    default_kind,
    default_expression,
    comment
FROM system.columns 
WHERE database = '{database}' AND table = '{table}'
ORDER BY position
```

### Find Tables by Column Name
```sql
SELECT database, table, name as column, type
FROM system.columns
WHERE name LIKE '%{pattern}%'
  AND database IN ('zod', 'clarity', 'overwatch')
```

### Materialized Views
```sql
SELECT 
    database,
    name,
    as_select
FROM system.tables 
WHERE engine = 'MaterializedView'
  AND database IN ('zod', 'clarity', 'overwatch')
```

## Request

$ARGUMENTS
