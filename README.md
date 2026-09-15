# BanquetDesk (Laravel + Local SQLite)

BanquetDesk now runs on **Laravel** with a **local SQLite database**. Supabase is no longer required.

## Start the app

```bash
cd "G:\Baquet desk laravel\laravel-tmp"
php artisan serve --host=127.0.0.1 --port=8000
```

Open: http://127.0.0.1:8000

## Default logins

| Username    | Password   | Role        |
|-------------|------------|-------------|
| admin       | admin123   | Admin       |
| manager     | snap123    | Manager     |
| accountant  | account123 | Accountant   |

Guest mode still works (session-only demo data, no database writes).

## What changed

- Frontend talks to `/api/db/query` instead of Supabase
- All 36 business tables live in `database/database.sqlite`
- Seeded users, employees, PDF settings, vendor categories, and chart of accounts

## Reset / re-seed local data

```bash
php artisan migrate:fresh --seed
```

## Optional: MySQL instead of SQLite

Edit `.env`:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=banquetdesk
DB_USERNAME=root
DB_PASSWORD=
```

Then run `php artisan migrate --seed`.
