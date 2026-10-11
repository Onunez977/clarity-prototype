# ClickHouse Data Analyzer

Deep analysis of ClickHouse table data, patterns, and anomalies using credentials from `dev/.env`.

## Connection

Read from `dev/.env`:
- `CLICKHOUSE_CLOUD_HOST`, `CLICKHOUSE_CLOUD_PORT`
- `CLICKHOUSE_CLOUD_USER`, `CLICKHOUSE_CLOUD_PASSWORD`

Execute queries:
```bash
curl -s "https://${HOST}:${PORT}" \
  --user "${USER}:${PASSWORD}" \
  --data "${QUERY} FORMAT TabSeparatedWithNames"
```

## Analysis Patterns

### Column Statistics
```sql
SELECT 
    count() as total_rows,
    countIf({column} IS NULL) as null_count,
    uniqExact({column}) as unique_values,
    min({column}) as min_value,
    max({column}) as max_value
FROM {database}.{table} FINAL
WHERE _sign != -1
```

### Value Distribution
```sql
SELECT {column}, count() as cnt
FROM {database}.{table} FINAL
WHERE _sign != -1
GROUP BY {column}
ORDER BY cnt DESC
LIMIT 20
```

### Time Series Analysis
```sql
SELECT 
    toStartOfDay({timestamp_column}) as day,
    count() as records
FROM {database}.{table} FINAL
WHERE _sign != -1
GROUP BY day
ORDER BY day DESC
LIMIT 30
```

### Data Freshness
```sql
SELECT 
    max({timestamp_column}) as latest_record,
    min({timestamp_column}) as oldest_record,
    dateDiff('day', min({timestamp_column}), max({timestamp_column})) as span_days
FROM {database}.{table} FINAL
WHERE _sign != -1
```

### Duplicate Detection
```sql
SELECT {key_columns}, count() as duplicates
FROM {database}.{table} FINAL
WHERE _sign != -1
GROUP BY {key_columns}
HAVING duplicates > 1
ORDER BY duplicates DESC
LIMIT 20
```

### Foreign Key Validation
```sql
SELECT count() as orphans
FROM {database}.{child_table} c FINAL
LEFT JOIN {database}.{parent_table} p FINAL ON c.{fk_column} = p.id
WHERE c._sign != -1 AND p.id IS NULL
```

## CDC Considerations

- Always use `FINAL` for ReplacingMergeTree tables
- Filter with `WHERE _sign != -1` to exclude soft-deleted records
- Check `_version` for CDC ordering issues

## Analysis Request

$ARGUMENTS
