> **Concept prototype.** This checkout is a detached sandbox of Clarity with sample data. It is not production and must not be pointed at Insight systems. Run it from [PROTOTYPE.md](PROTOTYPE.md).

# Clarity

[![Production](https://img.shields.io/badge/Production-FF6666)](https://clarity.insightlpr.io)
[![Staging](https://img.shields.io/badge/Staging-002dd3)](https://pre-platform.insightlpr.io)

## Local Development

First, install the dependencies from the root directory:

```bash
composer install
npm install
```

Configure your environment by copying the `.env.example` to `.env` and modify the settings to your local environment:

```bash
cp .env.example .env
```

Set your application encryption key (if not already set):

```bash
php artisan key:generate
# or:
php artisan k:g
```

Create a new **schema** called "platform" within your "zod" postgres database and add those credentials to your `.env` file:

```dotenv
DB_CONNECTION=platform
DB_HOST=127.0.0.1
DB_DATABASE=zod
DB_USERNAME=root
DB_PASSWORD=
```

Next, create the project's tables by deploying the database migrations and seeding your project:

```bash
php artisan dev:migrate --seed
```

You can run Vite locally to watch for design changes by running:

```bash
npm run dev
```

Otherwise, to compile styles for production, run:

```bash
npm run build
```

## Pint

This project uses [Laravel Pint](https://github.com/laravel/pint) for code styling. All PRs will run a GitHub Action to automatically run and apply Pint 
styling fixes to the entire codebase. You can run Pint locally by running:

```bash
./vendor/bin/pint
# or:
composer format
```

## Code Analysis

This project uses [Larastan](https://github.com/larastan/larastan) for code analysis. All PRs will run a GitHub Action to automatically check for errors in the codebase.
You can run Larastan locally by running:

```bash
./vendor/bin/phpstan analyse
# or:
composer analyse
```

## Testing

This project uses PHPUnit for testing with an automatic GitHub Action to run all tests for every PR. You can run the tests locally by running PHPUnit from the root of your directory:

```bash
php artisan test
```

> NOTE: You will not be able to run the test suite with the `--parallel` flag

### Local Testing

To not overwrite or delete your local database data, you can create a `env.testing` file and add a secondary, testing database credentials like so:

```dotenv
APP_KEY="YOUR_KEY_HERE"

DB_CONNECTION=platform
DB_HOST=127.0.0.1
DB_DATABASE=zod_testing
DB_USERNAME=root
DB_PASSWORD=
```
