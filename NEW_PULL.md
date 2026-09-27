# NEW PULL — REQUIRED STEPS

## Who should use this guide

This guide is for teammates who already have an older local version or database and are pulling the cleaned migration baseline.

## Important warning

**DO NOT simply run:**
`ash
php artisan migrate
`
on an old local database after pulling the migration squash.

The cleaned baseline uses new migration filenames, so old databases will hit a "table already exists" error.

## Recommended pull procedure

Use:
`ash
git checkout lyndon
git pull origin lyndon
`

Then:
`ash
docker compose down
docker compose up -d --build
`

Then:
`ash
docker compose exec backend php artisan migrate:fresh
`

**Explain:**
migrate:fresh deletes the LOCAL development database tables and recreates them.
This is intentional for this migration reset.

## Identity seeders

Required development identities:
`ash
docker compose exec backend php artisan db:seed --class=MockPersonaSeeder
`

Optional QA identities:
`ash
docker compose exec backend php artisan db:seed --class=TemporaryQaUsersSeeder
`

## Verify Docker

Use:
`ash
docker compose ps
`

Expected:
- rontend
- ackend
- db

## URLs

Frontend:
http://localhost:5173

Backend:
http://localhost:8000

## Verification commands

`ash
docker compose exec backend php artisan migrate:status
docker compose exec backend php artisan route:list --path=api/library
`

Expected:
- 18 migrations
- 49 Library routes

## Common problems

- **Unknown user / 401 after reset**
  → run MockPersonaSeeder
- **table already exists**
  → old DB was not reset; use migrate:fresh
- **frontend not running**
  → docker compose up -d --build then docker compose ps
- **stale containers**
  → docker compose down then up again

## Data warning

**migrate:fresh deletes LOCAL database data.**
Do not run against any database containing important data.

## Daily workflow after the one-time reset

Normally:
`ash
docker compose up -d
`
No need to migrate:fresh every day.
