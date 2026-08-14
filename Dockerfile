# ============================================================
# Stage 1: RoadRunner binary (2023.x — matches spiral PHP packages)
# ============================================================
FROM ghcr.io/roadrunner-server/roadrunner:2023.3.5 AS rr-binary

# ============================================================
# Stage 2: Build front-end assets with Node
# ============================================================
FROM node:20 AS frontend-builder
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# ============================================================
# Stage 3: Install PHP dependencies with Composer
# ============================================================
FROM composer:2.7 AS composer-builder
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install \
    --optimize-autoloader \
    --no-scripts \
    --no-interaction \
    --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-interaction

# ============================================================
# Stage 4: Final image — PHP 8.2 CLI + Octane + RoadRunner
# ============================================================
FROM php:8.2-cli

RUN apt-get update \
 && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    zip \
    unzip \
    git \
    curl \
 && docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    intl \
    sockets \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

# PHP ini: send errors to stderr so stdout stays clean for the RR pipe
RUN echo 'display_errors = stderr'    > /usr/local/etc/php/conf.d/zz-octane.ini \
 && echo 'log_errors = On'           >> /usr/local/etc/php/conf.d/zz-octane.ini \
 && echo 'error_log = /dev/stderr'   >> /usr/local/etc/php/conf.d/zz-octane.ini \
 && echo 'variables_order = "EGPCS"' >> /usr/local/etc/php/conf.d/zz-octane.ini

ENV APP_BASE_PATH=/var/www/html

WORKDIR /var/www/html

# Copy vendor from composer stage — vendor/bin/rr is the PHP proxy, keep it intact
COPY --from=composer-builder /app/vendor ./vendor

# Copy built front-end assets
COPY --from=frontend-builder /app/public/build ./public/build

# Copy application source (.dockerignore excludes vendor, .env, .rr.yaml)
COPY . .

# Copy the compatible RR binary (2023.x) from the official image
COPY --from=rr-binary /usr/bin/rr ./rr
RUN chmod +x ./rr

# Fix permissions
RUN chown -R www-data:www-data /var/www/html \
 && chmod -R 755 storage \
 && chmod -R 755 bootstrap/cache

RUN php artisan storage:link --no-interaction 2>/dev/null || true

USER www-data
EXPOSE 8000

# Run migrations then start Octane
CMD ["sh", "-c", "php artisan migrate --force && php artisan octane:start --server=roadrunner --host=0.0.0.0 --port=8000 --workers=4 --max-requests=500"]
