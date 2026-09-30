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

# -----------------------------------------------------------------------------
# Ferramentas OSINT (adapters reais).
#
# - Maigret (MIT, Python >= 3.10) — busca de username em milhares de sites.
# - Sherlock (MIT, Python >= 3.9) — busca de username em redes sociais.
# - Holehe (GPL-3.0) — verificação pública de cadastro por e-mail; executado
#   como processo separado e sempre com `--no-password-recovery`.
# - Versões fixadas para instalação reproduzível (`--build-arg`); nenhuma das
#   duas exige dependências de build (wheels musllinux disponíveis).
# - Binários em /usr/local/bin (maigret, sherlock).
# -----------------------------------------------------------------------------
ARG MAIGRET_VERSION=0.6.6
ARG SHERLOCK_VERSION=0.16.2
ARG HOLEHE_VERSION=1.61

RUN apk add --no-cache python3 py3-pip \
    && pip install --break-system-packages --no-cache-dir \
        "maigret==${MAIGRET_VERSION}" \
        "sherlock-project==${SHERLOCK_VERSION}" \
        "holehe==${HOLEHE_VERSION}" \
    && maigret --version > /dev/null \
    && sherlock --version > /dev/null \
    && pip show holehe > /dev/null

# -----------------------------------------------------------------------------
# WhatsMyName — dataset público de verificação de username.
#
# - Licença CC BY-SA 4.0 (© Micah Hoffman e colaboradores); o LICENSE é mantido
#   ao lado do dataset para cumprir a atribuição.
# - O upstream é um dataset consumido por checkers externos (os checkers
#   embutidos foram removidos em 2023), então fixamos o arquivo por commit.
# - O build falha se o JSON baixado não puder ser interpretado.
# -----------------------------------------------------------------------------
ARG WHATSMYNAME_COMMIT=062bcfe48df79fa618e96edc79dc9673f3fe5643

RUN mkdir -p /opt/whatsmyname \
    && wget -qO /opt/whatsmyname/wmn-data.json "https://raw.githubusercontent.com/WebBreacher/WhatsMyName/${WHATSMYNAME_COMMIT}/wmn-data.json" \
    && wget -qO /opt/whatsmyname/LICENSE.md "https://raw.githubusercontent.com/WebBreacher/WhatsMyName/${WHATSMYNAME_COMMIT}/LICENSE.md" \
    && printf '%s' "${WHATSMYNAME_COMMIT}" > /opt/whatsmyname/REVISION \
    && php -r 'exit(is_array(json_decode((string) file_get_contents("/opt/whatsmyname/wmn-data.json"), true)["sites"] ?? null) ? 0 : 1);'

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
