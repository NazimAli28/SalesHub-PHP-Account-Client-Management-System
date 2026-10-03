# SalesHub API (backend)

Laravel 13 REST API for SalesHub v2.

## Requirements

- PHP 8.3+ with `intl`, `zip`, `pdo_mysql`, `pdo_sqlite`, `sodium`
- Composer 2
- MySQL 8 / MariaDB 10.6+ (SQLite is used for tests)

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

## Quality checks

```bash
composer lint      # Laravel Pint (code style)
composer analyse   # Larastan / PHPStan level 6
composer test      # Pest
```
