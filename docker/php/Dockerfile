# =============================================================================
# Investigação Pública — Imagem Unificada (PHP 8.5 FPM + Nginx)
# =============================================================================

FROM composer:2 AS composer

FROM php:8.5-fpm-alpine

# Instalar Nginx, Supervisor (opcional ou iniciar via script) e dependências
RUN apk add --no-cache \
        nginx \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        python3 \
        py3-pip \
        $PHPIZE_DEPS

# Configurar extensões PHP
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

# Ferramentas OSINT (Maigret, Sherlock, Holehe, Instagrapi)
ARG MAIGRET_VERSION=0.6.6
ARG SHERLOCK_VERSION=0.16.2
ARG HOLEHE_VERSION=1.61
ARG INSTAGRAPI_VERSION=2.7.4

RUN pip install --break-system-packages --no-cache-dir \
        "maigret==${MAIGRET_VERSION}" \
        "sherlock-project==${SHERLOCK_VERSION}" \
        "holehe==${HOLEHE_VERSION}" \
        "instagrapi==${INSTAGRAPI_VERSION}"

# Correção do Holehe
RUN HOLEHE_CORE="$(python3 -c 'import holehe.core as core; print(core.__file__)')" \
    && sed -i 's|parser\.add_argument("-T","--timeout", default=10|parser.add_argument("-T","--timeout", type=int, default=10|' "$HOLEHE_CORE"

# WhatsMyName dataset
ARG WHATSMYNAME_COMMIT=062bcfe48df79fa618e96edc79dc9673f3fe5643
RUN mkdir -p /opt/whatsmyname \
    && wget -qO /opt/whatsmyname/wmn-data.json "https://raw.githubusercontent.com/WebBreacher/WhatsMyName/${WHATSMYNAME_COMMIT}/wmn-data.json"

# Copiar Composer
COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar código da aplicação
COPY . /var/www/html

# Instalar dependências do Composer
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copiar configuração customizada do Nginx para o diretório padrão do Alpine
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

# Ajustar permissões
# `database/backups` recebe o dump gerado pelo painel admin.
RUN mkdir -p /var/www/html/database/backups \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database/backups

# Porta que o Railway vai expor (usando a variável de ambiente $PORT do Railway)
EXPOSE 8080

# Script de inicialização para rodar Nginx e PHP-FPM simultaneamente
RUN echo '#!/bin/sh' > /start.sh \
    && echo "sed -i \"s/listen 80;/listen \$PORT;/g\" /etc/nginx/http.d/default.conf" >> /start.sh \
    && echo 'sed -i "s/fastcgi_pass app:9000;/fastcgi_pass 127.0.0.1:9000;/g" /etc/nginx/http.d/default.conf' >> /start.sh \
    && echo 'php-fpm -D' >> /start.sh \
    && echo 'nginx -g "daemon off;"' >> /start.sh \
    && chmod +x /start.sh

CMD ["/start.sh"]