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
# Script de inicialização unificado (MySQL + Redis + PHP-FPM + Worker + Nginx)
RUN cat << "EOF" > /start.sh
#!/bin/sh

# 1. Ajustar a porta dinâmica do Railway no Nginx no boot (fallback para 8080)
TARGET_PORT="${PORT:-8080}"
sed -i "s/listen 80;/listen ${TARGET_PORT};/g" /etc/nginx/http.d/default.conf

# 2. Ajustar o FastCGI para falar com o PHP-FPM via IP local
sed -i "s/fastcgi_pass app:9000;/fastcgi_pass 127.0.0.1:9000;/g" /etc/nginx/http.d/default.conf

# 3. Inicializar e subir o MySQL
if [ ! -d "/var/lib/mysql/mysql" ]; then
    mysql_install_db --user=mysql --datadir=/var/lib/mysql > /dev/null 2>&1
fi
mysqld --user=mysql --bind-address=127.0.0.1 &
echo "Aguardando MySQL..."
while ! mysqladmin ping -h 127.0.0.1 --silent; do
    sleep 1
done

# 4. Criar Banco de Dados e Usuário
DB_NAME="${DB_DATABASE:-investigacao_publica}"
DB_USER="${DB_USERNAME:-investigacao}"
DB_PASS="${DB_PASSWORD:-secret}"

mysql -u root -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;"
mysql -u root -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -u root -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
mysql -u root -e "FLUSH PRIVILEGES;"

# 5. Iniciar Redis
redis-server --daemonize yes

# 6. Rodar Migrações
php artisan migrate --force

# 7. Iniciar PHP-FPM em background
php-fpm -D

# 8. Iniciar Queue Worker do Laravel
php artisan queue:work redis --sleep=3 --tries=3 --timeout=90 > /dev/null 2>&1 &

# 9. Iniciar Nginx em primeiro plano
nginx -g "daemon off;"
EOF

RUN chmod +x /start.sh

CMD ["/start.sh"]