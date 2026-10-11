#!/usr/bin/env python3
"""
Export ClickHouse Cloud schema to SQL migration files for local Docker environment.

Usage:
    1. Copy .env.example to .env and fill in your ClickHouse Cloud credentials
    2. pip install -r requirements.txt
    3. python export_schema.py

Output:
    - Creates SQL files in ../migrations/cloud_export/ directory
    - One file per database with all tables, views, and materialized views
"""

import os
import sys
from pathlib import Path
from datetime import datetime

import clickhouse_connect
from dotenv import load_dotenv


def get_client():
    """Create ClickHouse Cloud client from environment variables."""
    load_dotenv()

    host = os.getenv('CLICKHOUSE_CLOUD_HOST')
    port = int(os.getenv('CLICKHOUSE_CLOUD_PORT', '8443'))
    user = os.getenv('CLICKHOUSE_CLOUD_USER', 'default')
    password = os.getenv('CLICKHOUSE_CLOUD_PASSWORD', '')
    secure = os.getenv('CLICKHOUSE_CLOUD_SECURE', 'true').lower() == 'true'

    if not host:
        print("Error: CLICKHOUSE_CLOUD_HOST not set in .env file")
        print("Copy .env.example to .env and fill in your credentials")
        sys.exit(1)

    print(f"Connecting to {host}:{port}...")

    return clickhouse_connect.get_client(
        host=host,
        port=port,
        username=user,
        password=password,
        secure=secure
    )


def get_databases(client):
    """Get list of databases to export from environment or query all."""
    db_env = os.getenv('CLICKHOUSE_DATABASES', '')

    if db_env:
        return [db.strip() for db in db_env.split(',') if db.strip()]

    # Query all non-system databases
    result = client.query("""
        SELECT name
        FROM system.databases
        WHERE name NOT IN ('system', 'INFORMATION_SCHEMA', 'information_schema', 'default')
        ORDER BY name
    """)
    return [row[0] for row in result.result_rows]


def get_tables(client, database):
    """Get all tables in a database with their engine type."""
    result = client.query(f"""
        SELECT
            name,
            engine,
            create_table_query
        FROM system.tables
        WHERE database = '{database}'
            AND name NOT LIKE '.%'
            AND name NOT LIKE 'altinity_%'
            AND name NOT LIKE 'replica_%'
        ORDER BY
            CASE
                WHEN engine LIKE '%View%' THEN 2
                WHEN engine = 'MaterializedView' THEN 3
                ELSE 1
            END,
            name
    """)
    return result.result_rows


def clean_create_statement(create_query, database):
    """Clean up CREATE statement for local use."""
    # Remove UUID clause (not needed locally)
    import re

    # Remove UUID
    create_query = re.sub(r"\s+UUID\s+'[^']+'", '', create_query)

    # Replace cloud-specific cluster references if any
    create_query = re.sub(r"ON CLUSTER\s+'[^']+'", '', create_query)
    create_query = re.sub(r"ON CLUSTER\s+\w+", '', create_query)

    # Ensure database prefix is correct
    # The create_table_query already has full database.table format

    return create_query.strip()


def categorize_tables(tables):
    """Categorize tables by type for ordered creation."""
    regular_tables = []
    views = []
    materialized_views = []

    for name, engine, create_query in tables:
        if engine == 'MaterializedView':
            materialized_views.append((name, engine, create_query))
        elif 'View' in engine:
            views.append((name, engine, create_query))
        else:
            regular_tables.append((name, engine, create_query))

    return regular_tables, views, materialized_views


def generate_sql_file(database, tables, output_dir):
    """Generate SQL migration file for a database."""
    regular_tables, views, materialized_views = categorize_tables(tables)

    lines = []
    lines.append("-- ============================================================")
    lines.append("-- Schema export from ClickHouse Cloud")
    lines.append(f"-- Database: {database}")
    lines.append(f"-- Generated: {datetime.now().isoformat()}")
    lines.append("-- ============================================================")
    lines.append("")
    lines.append(f"CREATE DATABASE IF NOT EXISTS {database};")
    lines.append("")

    # Regular tables first
    if regular_tables:
        lines.append("-- ------------------------------------------------------------")
        lines.append("-- Tables")
        lines.append("-- ------------------------------------------------------------")
        lines.append("")

        for name, engine, create_query in regular_tables:
            lines.append(f"-- Table: {name} (Engine: {engine})")
            lines.append(f"DROP TABLE IF EXISTS {database}.{name};")
            lines.append(clean_create_statement(create_query, database) + ";")
            lines.append("")

    # Views second
    if views:
        lines.append("-- ------------------------------------------------------------")
        lines.append("-- Views")
        lines.append("-- ------------------------------------------------------------")
        lines.append("")

        for name, engine, create_query in views:
            lines.append(f"-- View: {name}")
            lines.append(f"DROP VIEW IF EXISTS {database}.{name};")
            lines.append(clean_create_statement(create_query, database) + ";")
            lines.append("")

    # Materialized views last
    if materialized_views:
        lines.append("-- ------------------------------------------------------------")
        lines.append("-- Materialized Views")
        lines.append("-- ------------------------------------------------------------")
        lines.append("")

        for name, engine, create_query in materialized_views:
            lines.append(f"-- Materialized View: {name}")
            lines.append(f"DROP TABLE IF EXISTS {database}.{name};")
            lines.append(clean_create_statement(create_query, database) + ";")
            lines.append("")

    # Write file
    output_file = output_dir / f"cloud_{database}_schema.sql"
    output_file.write_text('\n'.join(lines))

    return output_file, len(regular_tables), len(views), len(materialized_views)


def main():
    print("=" * 60)
    print("ClickHouse Cloud Schema Exporter")
    print("=" * 60)
    print()

    # Connect to ClickHouse Cloud
    client = get_client()
    print("Connected successfully!")
    print()

    # Get databases to export
    databases = get_databases(client)
    print(f"Databases to export: {', '.join(databases)}")
    print()

    # Create output directory
    output_dir = Path(__file__).parent.parent / 'dev' / 'cloud_export'
    output_dir.mkdir(parents=True, exist_ok=True)
    print(f"Output directory: {output_dir}")
    print()

    # Export each database
    total_tables = 0
    total_views = 0
    total_mvs = 0

    for database in databases:
        print(f"Exporting {database}...")

        tables = get_tables(client, database)
        if not tables:
            print(f"  No tables found in {database}")
            continue

        output_file, n_tables, n_views, n_mvs = generate_sql_file(
            database, tables, output_dir
        )

        total_tables += n_tables
        total_views += n_views
        total_mvs += n_mvs

        print(f"  Tables: {n_tables}, Views: {n_views}, Materialized Views: {n_mvs}")
        print(f"  Written to: {output_file.name}")

    print()
    print("=" * 60)
    print("Export complete!")
    print(f"  Total tables: {total_tables}")
    print(f"  Total views: {total_views}")
    print(f"  Total materialized views: {total_mvs}")
    print()
    print("To apply to local Docker ClickHouse:")
    print("  docker exec -i clickhouse-server clickhouse-client --multiquery < migrations/cloud_export/<file>.sql")
    print("=" * 60)


if __name__ == '__main__':
    main()
