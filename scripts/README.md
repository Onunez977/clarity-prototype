# Scripts

Utility scripts for the platform project.

## extract_sql_queries.py

Extracts SQL queries from Laravel JSON log files and converts them into executable SQL files with bindings replaced.

### Usage

```bash
# Use default paths (storage/logs/db_error.log -> sql_queries/)
python scripts/extract_sql_queries.py

# Specify a log file
python scripts/extract_sql_queries.py storage/logs/db_error-2025-10-10.log

# Specify both log file and output directory
python scripts/extract_sql_queries.py storage/logs/db_error-2025-10-10.log output/queries

# Show help
python scripts/extract_sql_queries.py --help
```

### Features

- Parses Laravel JSON log files containing SQL queries with bindings
- Replaces `?` placeholders with actual binding values
- Properly formats SQL for readability (newlines after major keywords)
- Handles PostgreSQL-specific types (JSON, timestamps, etc.)
- Adds metadata comments (query number, user, log line)
- Creates separate `.sql` files for each query

### Output

Each query is saved as a separate file (`query-001.sql`, `query-002.sql`, etc.) with:
- Comment header showing query number, user, and original log line
- Formatted SQL with bindings replaced
- Ready to execute in any PostgreSQL client

### Example Output

```sql
-- Query #1
-- User: user@example.com
-- Log line: 42

select "scans"."id", "scans"."ocr"
FROM "scans"
WHERE "kit_id" in (123, 456)
  AND "scanned_at" between '2025-04-01 05:00:00' and '2025-10-10 00:00:00'
ORDER BY "scanned_at" desc
LIMIT 51;
```

### Requirements

- Python 3.6+
- No external dependencies (uses standard library only)
