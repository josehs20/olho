# syntax=docker/dockerfile:1

# =============================================================================
# Investigação Pública — imagem de aplicação PHP (Laravel 13)
# Stack: PHP 8.5 FPM (Alpine) + Composer, usuário não-root.
# =============================================================================

FROM composer:2 AS composer

FROM php:8.5-fpm-alpine

# Dependências de sistema necessárias para compilar as extensões.
RUN apk add --no-cache \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        $PHPIZE_DEPS

# Extensões que a imagem base não traz embutidas.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        pcntl \
        pdo_mysql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis

# Remove apenas as ferramentas de build; mantém as libs de runtime.
RUN apk del $PHPIZE_DEPS

# Composer (multi-stage).
COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Usuário não-root para o FPM.
RUN addgroup -g 1000 -S app \
    && adduser -u 1000 -S app -G app \
    && mkdir -p /var/www/html/storage /var/www/html/bootstrap/cache \
    && chown -R app:app /var/www/html

# Código da aplicação.
COPY --chown=app:app . /var/www/html

USER app

EXPOSE 9000

CMD ["php-fpm"]
