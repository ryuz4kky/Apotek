FROM php:8.5-cli-bookworm
RUN apt-get update && apt-get install -y libpq-dev libicu-dev libzip-dev unzip git postgresql-client && docker-php-ext-install pdo_pgsql bcmath intl zip pcntl && rm -rf /var/lib/apt/lists/*
RUN apt-get update && apt-get install -y curl ca-certificates gnupg && install -d /usr/share/postgresql-common/pgdg && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" > /etc/apt/sources.list.d/pgdg.list && apt-get update && apt-get install -y postgresql-client-17 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-interaction --prefer-dist && mkdir -p storage/app/private storage/framework/{cache,sessions,views} && chmod -R 775 storage bootstrap/cache
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
