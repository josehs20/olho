# =============================================================================
# Investigação Pública — Imagem Unificada Completa (Railway / All-in-One)
# =============================================================================

FROM composer:2 AS composer

FROM php:8.5-fpm-alpine

# Instalar Nginx, MySQL, Redis, Python e dependências do sistema
RUN apk add --no-cache \
        nginx \
        mysql \
        mysql-client \
        redis \
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

# Criar o arquivo .env automaticamente do exemplo se não existir
RUN [ -f .env ] || cp .env.example .env

# Instalar dependências do Composer
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copiar configuração customizada do Nginx para o diretório padrão do Alpine
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

# Ajustar permissões da aplicação e diretórios do MySQL/Redis
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && mkdir -p /var/run/mysqld /var/lib/mysql \
    && chown -R mysql:mysql /var/run/mysqld /var/lib/mysql

# Porta padrão exposta (o script substitui dinamicamente pela porta do Railway)
EXPOSE 8080

# Script de inicialização unificado (MySQL + Redis + PHP-FPM + Worker + Scheduler + Nginx)
RUN echo '#!/bin/sh' > /start.sh \
    # 1. Configurar porta dinâmica do Nginx
    && echo 'sed -i "s/listen 80;/listen $PORT;/g" /etc/nginx/http.d/default.conf' >> /start.sh \
    # 2. Ajustar FastCGI para o PHP-FPM local
    && echo 'sed -i "s/fastcgi_pass app:9000;/fastcgi_pass 127.0.0.1:9000;/g" /etc/nginx/http.d/default.conf' >> /start.sh \
    # 3. Inicializar banco de dados MySQL local se não estiver inicializado
    && echo 'if [ ! -d "/var/lib/mysql/mysql" ]; then' >> /start.sh \
    && echo '    mysql_install_db --user=mysql --datadir=/var/lib/mysql > /dev/null' >> /start.sh \
    && echo 'fi' >> /start.sh \
    # 4. Iniciar o MySQL em background
    && echo 'mysqld --user=mysql --bind-address=127.0.0.1 & ' >> /start.sh \
    && echo 'echo "Aguardando o MySQL iniciar..."' >> /start.sh \
    && echo 'while ! mysqladmin ping -h 127.0.0.1 --silent; do sleep 1; done' >> /start.sh \
    # 5. Criar banco de dados se não existir
    && echo 'mysql -u root -e "CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE:-investigacao_publica}\`;"' >> /start.sh \
    && echo 'mysql -u root -e "CREATE USER IF NOT EXISTS \'${DB_USERNAME:-investigacao}\'@\'localhost\' IDENTIFIED BY \'${DB_PASSWORD:-secret}\';"' >> /start.sh \
    && echo 'mysql -u root -e "GRANT ALL PRIVILEGES ON \`${DB_DATABASE:-investigacao_publica}\`.* TO \'${DB_USERNAME:-investigacao}\'@\'localhost\';"' >> /start.sh \
    && echo 'mysql -u root -e "FLUSH PRIVILEGES;"' >> /start.sh \
    # 6. Iniciar Redis em background
    && echo 'redis-server --daemonize yes' >> /start.sh \
    # 7. Executar migrações do Laravel automaticamente no boot
    && echo 'php artisan migrate --force' >> /start.sh \
    # 8. Iniciar PHP-FPM em background
    && echo 'php-fpm -D' >> /start.sh \
    # 9. Iniciar Laravel Queue Worker em background
    && echo 'php artisan queue:work redis --sleep=3 --tries=3 --timeout=90 > /dev/null 2>&1 &' >> /start.sh \
    # 10. Iniciar Nginx em primeiro plano (mantém o container vivo)
    && echo 'nginx -g "daemon off;"' >> /start.sh \
    && chmod +x /start.sh

CMD ["/start.sh"]