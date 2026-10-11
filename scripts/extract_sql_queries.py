import json
import os
import sys
from pathlib import Path

# Get the project root directory (parent of scripts/)
SCRIPT_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = SCRIPT_DIR.parent

# Default paths relative to project root
DEFAULT_LOG_FILE = PROJECT_ROOT / 'storage' / 'logs' / 'db_error.log'
DEFAULT_OUTPUT_DIR = PROJECT_ROOT / 'sql_queries'


def format_binding(value):
    """Format a binding value for SQL."""
    if value is None or value == 'null':
        return 'NULL'
    if isinstance(value, bool):
        return 'true' if value else 'false'
    if isinstance(value, (int, float)):
        return str(value)
    if isinstance(value, str):
        # Check if it's a JSON string that should be kept as-is for PostgreSQL
        if value.startswith('{') and value.endswith('}'):
            # Escape single quotes and wrap in single quotes for PostgreSQL JSON
            return "'" + value.replace("'", "''") + "'"
        # Regular string - escape single quotes
        return "'" + value.replace("'", "''") + "'"
    # Fallback for other types
    return "'" + str(value).replace("'", "''") + "'"


def parse_log_entries(log_file_path):
    """Parse JSON log entries and extract SQL queries with bindings."""
    entries = []

    with open(log_file_path, 'r') as f:
        for line_num, line in enumerate(f, 1):
            line = line.strip()
            if not line:
                continue

            try:
                log_entry = json.loads(line)
                context = log_entry.get('context', {})

                sql = context.get('sql')
                bindings = context.get('bindings', [])
                user = context.get('user', 'unknown')

                if sql:
                    entries.append({
                        'sql': sql,
                        'bindings': bindings,
                        'user': user,
                        'line_num': line_num
                    })
            except json.JSONDecodeError as e:
                print(f"Warning: Skipping line {line_num} - invalid JSON: {e}")
                continue

    return entries


def substitute_bindings(sql, bindings):
    """Replace ? placeholders with actual binding values."""
    result = sql
    for binding in bindings:
        formatted = format_binding(binding)
        result = result.replace('?', formatted, 1)
    return result


def format_sql(sql):
    """Basic SQL formatting for readability."""
    # Add newlines after major keywords for better readability
    keywords = ['FROM', 'WHERE', 'ORDER BY', 'LIMIT', 'INNER JOIN', 'LEFT JOIN', 'RIGHT JOIN']
    formatted = sql
    for keyword in keywords:
        formatted = formatted.replace(f' {keyword.lower()} ', f'\n{keyword.upper()} ')
    return formatted


def main():
    """Main function to parse command line arguments and extract queries."""
    # Parse command line arguments
    if len(sys.argv) > 1:
        if sys.argv[1] in ['-h', '--help']:
            print("Usage: python extract_sql_queries.py [log_file] [output_dir]")
            print()
            print("Arguments:")
            print("  log_file    Path to the log file (default: storage/logs/db_error.log)")
            print("  output_dir  Directory to write SQL files (default: sql_queries/)")
            print()
            print("Examples:")
            print("  python scripts/extract_sql_queries.py")
            print("  python scripts/extract_sql_queries.py storage/logs/db_error-2025-10-10.log")
            print("  python scripts/extract_sql_queries.py storage/logs/db_error-2025-10-10.log output/queries")
            sys.exit(0)

        log_file = Path(sys.argv[1])
        # If path is relative, make it relative to project root
        if not log_file.is_absolute():
            log_file = PROJECT_ROOT / log_file
    else:
        log_file = DEFAULT_LOG_FILE

    if len(sys.argv) > 2:
        output_dir = Path(sys.argv[2])
        # If path is relative, make it relative to project root
        if not output_dir.is_absolute():
            output_dir = PROJECT_ROOT / output_dir
    else:
        output_dir = DEFAULT_OUTPUT_DIR

    # Validate log file exists
    if not log_file.exists():
        print(f"Error: Log file not found: {log_file}")
        sys.exit(1)

    # Create output directory
    output_dir.mkdir(parents=True, exist_ok=True)

    print(f"Reading log file: {log_file}")
    print(f"Output directory: {output_dir}")
    print()

    # Parse all log entries
    entries = parse_log_entries(str(log_file))

    print(f"Found {len(entries)} SQL queries in log file")

    if len(entries) == 0:
        print("No queries found. Exiting.")
        sys.exit(0)

    # Write each query to a separate file
    for idx, entry in enumerate(entries, 1):
        sql_with_bindings = substitute_bindings(entry['sql'], entry['bindings'])
        formatted_sql = format_sql(sql_with_bindings)

        filename = f'query-{idx:03}.sql'
        filepath = output_dir / filename

        with open(filepath, 'w') as out:
            out.write(f"-- Query #{idx}\n")
            out.write(f"-- User: {entry['user']}\n")
            out.write(f"-- Log line: {entry['line_num']}\n\n")
            out.write(formatted_sql)
            out.write(';\n')

    print(f"Extracted {len(entries)} queries to {output_dir}/")


if __name__ == '__main__':
    main()

