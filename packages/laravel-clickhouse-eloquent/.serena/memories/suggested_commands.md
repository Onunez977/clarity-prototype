# Suggested Commands

## Running Tests

```bash
# Install dependencies
composer install

# Run all tests (requires local ClickHouse)
php vendor/bin/phpunit

# Unit tests only (no DB required)
php vendor/bin/phpunit tests/Unit/

# Integration tests (requires CLICKHOUSE_CLOUD_HOST env)
php vendor/bin/phpunit tests/Integration/

# Docker-based tests (local ClickHouse via Docker)
docker-compose -f docker-compose.test.yaml run php sh /src/tests.bootstrap.sh
```

## Git Commands
```bash
git status
git add .
git commit -m "message"
git push
git pull
git log --oneline -10
git diff
```
