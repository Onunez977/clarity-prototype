# Shared Lib for Laravel apps


InsightLPR Package for supporting Laravel Framework base applications.

## Installation

You can install the package via composer:

```bash
composer require insight-lpr/black-adam
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="black-adam-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="black-adam-config"
```

This is the contents of the published config file:

```php
return [
];
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="black-adam-views"
```

## Usage

```php
$insightLPR = new InsightLPR\BlackAdam();
echo $insightLPR->echoPhrase('Hello, InsightLPR!');
```

## Testing

```bash
composer test
```

## Release / Tagging (Rollout Process)

We ship this package to downstream apps (for example: Overwatch, Backyard) via Git tags.

Typical versioning:
- We usually bump the **third** digit for normal changes (example: `v3.4.12` -> `v3.4.13`).
- Bump the **middle** digit only for potentially breaking changes.

Steps:
1. Merge your PR into `main`.
2. Pull latest `main` locally:
   ```bash
   git checkout main
   git pull
   ```
3. Check the current/latest tags:
   ```bash
   git tag
   ```
4. Create a new tag (example):
   ```bash
   git tag v3.4.13
   git push origin v3.4.13
   ```
5. Update downstream apps to the new tag and deploy:
   - Run `composer update insight-lpr/black-adam` in Overwatch and Backyard.
   - Production reminder: ensure composer updates are performed on the prod app servers for both apps:
     - `10.100.3.142` (prod + pre-prod host)
       - Overwatch (prod): `~/code/overwatch`
       - Overwatch (pre-prod): `~/code/overwatch_test`
       - Backyard: `~/code/backyard`
     - `10.100.5.93` (job host)
       - Overwatch: `~/code/overwatch`
       - Backyard: `~/code/backyard`

