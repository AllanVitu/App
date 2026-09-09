# ============================================================================
#  Image PHP-FPM 8.3 pour l'API SaaS
#  Extensions : pdo_pgsql (PostgreSQL), opcache, intl
# ============================================================================
FROM php:8.3-fpm-alpine

# --- Dépendances système + extensions PHP ---
RUN set -eux; \
    apk add --no-cache \
        postgresql-client \
        icu-libs \
        git \
        unzip \
        bash; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        icu-dev; \
    docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_pgsql \
        opcache \
        intl; \
    apk del .build-deps

# --- Composer (gestion des dépendances PHP) ---
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- Configuration PHP applicative ---
COPY php.ini /usr/local/etc/php/conf.d/99-app.ini

WORKDIR /var/www/html

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# php-fpm tourne en www-data (UID 82 sur Alpine)
USER www-data

EXPOSE 9000
# Les migrations sont jouées avant php-fpm : cf. entrypoint.sh.
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]
