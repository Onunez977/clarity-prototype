# ClickHouse Query

Execute a SQL query against ClickHouse Cloud using the dev/.env credentials.

## Instructions

1. Read credentials from `dev/.env`:
   - Host: `CLICKHOUSE_CLOUD_HOST`
   - Port: `CLICKHOUSE_CLOUD_PORT` (8443)
   - User: `CLICKHOUSE_CLOUD_USER`
   - Password: `CLICKHOUSE_CLOUD_PASSWORD`

2. Read cached table names from `dev/.tables` (if it exists). This file contains available tables per database.

3. Execute the query using curl with `FORMAT TabSeparatedWithNames`:
```bash
curl -s "https://${HOST}:${PORT}" \
  --user "${USER}:${PASSWORD}" \
  --data "${QUERY} FORMAT TabSeparatedWithNames"
```

4. Format the results as a markdown table and return to the user.

## Table Name Cache

- **Cache file**: `dev/.tables`
- **Refresh when**: 
  - User explicitly asks to refresh tables
  - A table referenced in the query is not found (UNKNOWN_TABLE error)
  - Cache file doesn't exist
- **Do NOT refresh**: On every query - use cached values

### Cache Format (dev/.tables)
```
# Database: zod
zod.table1
zod.table2

# Database: clarity
clarity.table1
```

### Refresh Command
When refreshing, query each database from `CLICKHOUSE_DATABASES` in dev/.env:
```bash
curl -s "https://${HOST}:${PORT}" \
  --user "${USER}:${PASSWORD}" \
  --data "SELECT concat(database, '.', name) as table_name FROM system.tables WHERE database = '${DB}' ORDER BY name FORMAT TabSeparated"
```

## Query

$ARGUMENTS
