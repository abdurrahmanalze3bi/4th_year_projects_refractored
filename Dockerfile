# ============================================================
# Stage 1: Build front-end assets with Node
# ============================================================
FROM node:20 AS frontend-builder

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN npm run build

# ============================================================
# Stage 2: Install PHP dependencies with Composer
# ============================================================
FROM composer:2.7 AS composer-builder

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --optimize-autoloader \
    --no-scripts \
    --no-interaction

COPY . .
RUN composer run-script post-autoload-dump

# ============================================================
# Stage 3: Final image — PHP 8.2 + Apache
# ============================================================
FROM php:8.2-apache

# Enable Apache rewrite module
# Point DocumentRoot to Laravel's public/ folder
RUN a2enmod rewrite \
 && sed -ri 's!DocumentRoot /var/www/html!DocumentRoot /var/www/html/public!g' \
    /etc/apache2/sites-available/000-default.conf \
 && sed -ri 's!<Directory /var/www/html>!<Directory /var/www/html/public>!g' \
    /etc/apache2/apache2.conf

# Allow .htaccess overrides
RUN sed -ri 's!AllowOverride None!AllowOverride All!g' \
    /etc/apache2/apache2.conf

# Install system libraries and PHP extensions
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
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Copy vendor from composer stage
COPY --from=composer-builder /app/vendor ./vendor

# Copy built front-end assets from node stage
COPY --from=frontend-builder /app/public/build ./public/build

# Copy the rest of the application
COPY . .

# Fix storage and cache permissions
RUN chown -R www-data:www-data /var/www/html \
 && chmod -R 755 storage \
 && chmod -R 755 bootstrap/cache

EXPOSE 80

CMD ["apache2-foreground"]
