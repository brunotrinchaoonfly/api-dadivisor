FROM php:8.2-fpm-alpine

# Extensões PHP necessárias
RUN apk add --no-cache \
        libzip-dev \
        zip \
        unzip \
        curl \
    && docker-php-ext-install zip pcntl

# Redis extension via PECL
RUN apk add --no-cache autoconf g++ make \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf g++ make

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copia dependências primeiro (layer cache)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copia o restante da aplicação
COPY . .

RUN php artisan config:cache \
    && php artisan route:cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
