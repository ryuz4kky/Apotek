#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
if [ ! -f .env ]; then
  cp .env.example .env
  docker run --rm -v "$PWD:/app" -w /app composer:2 php -r '$p=".env"; $s=file_get_contents($p); $s=str_replace(["replace-with-random-password","replace-before-seeding"],[bin2hex(random_bytes(24)),bin2hex(random_bytes(12))],$s); file_put_contents($p,$s);'
fi
docker compose build app
docker compose run --rm --no-deps app composer install --no-interaction
docker compose run --rm --no-deps app php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if(!config("app.key")) Illuminate\Support\Facades\Artisan::call("key:generate",["--force"=>true]);' 
docker compose run --rm node npm ci
docker compose run --rm node npm run build
docker compose up -d db app
docker compose exec -T app php artisan migrate --seed --force
docker compose up -d --no-build worker
printf '\nAplikasi tersedia di http://localhost:8088\nAkun: kasir@apotek.test; kata sandi ada pada DEMO_PASSWORD di .env.\n'
