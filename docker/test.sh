#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '\''apotek_test'\''" | tr -d " " | grep -q 1 || createdb -U "$POSTGRES_USER" apotek_test'
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=apotek_test -e CACHE_STORE=array -e SESSION_DRIVER=array -e BCRYPT_ROUNDS=4 app php artisan test
