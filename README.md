# Daily Cash Voucher Laravel API

Laravel 13, MySQL and Sanctum provide the trusted backend for the Flutter
manager and bookkeeper applications.

## First time setup

Create empty MySQL databases named `daily_cash_voucher` and
`daily_cash_voucher_test`, then run:

    composer install
    copy .env.example .env
    php artisan key:generate
    php artisan migrate --seed
    php artisan storage:link

Set the MySQL host, port, database, username and password in `.env` first. Seed
credentials can be overridden with `SEED_MANAGER_EMAIL`,
`SEED_MANAGER_PASSWORD`, `SEED_ADMIN_EMAIL`, `SEED_ADMIN_PASSWORD`,
`SEED_BOOKKEEPER_EMAIL` and
`SEED_BOOKKEEPER_PASSWORD`.

## Run

    php artisan serve --host=127.0.0.1 --port=8000

The health endpoint is `http://127.0.0.1:8000/up`; the API base URL is
`http://127.0.0.1:8000/api`.

## Test and format

Tests use the isolated `daily_cash_voucher_test` database from `phpunit.xml`.

    vendor\bin\pint --test
    php artisan test

The feature suite verifies authentication, authorization, voucher CRUD,
posting, idempotent replay, journal persistence, opening-float rollover and
bookkeeper visibility. It also verifies administrator user creation, editing,
password reset, role/outlet assignment and deactivation.

## Production checklist

- Replace all seed passwords and remove unused development tokens.
- Use a least-privilege MySQL user instead of `root`.
- Set `APP_ENV=production`, `APP_DEBUG=false` and the public HTTPS `APP_URL`.
- Configure HTTPS, backups, log rotation and a production PHP web server.
- Restrict CORS to the deployed web origin if Flutter web is published.
- Configure private receipt delivery if receipt URLs must not be public.
