# ============================================================================
#  Image PHP-FPM 8.3 pour l'API SaaS
#  Extensions : pdo_pgsql (PostgreSQL), opcache, intl, zip (export des données)
# ============================================================================
FROM php:8.3-fpm-alpine

# --- Dépendances système + extensions PHP ---
RUN set -eux; \
    apk add --no-cache \
        postgresql-client \
        icu-libs \
        libzip \
        git \
        unzip \
        bash; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        icu-dev \
        libzip-dev; \
    docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_pgsql \
        opcache \
        intl \
        zip; \
    apk del .build-deps

# --- Composer (gestion des dépendances PHP) ---
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- Configuration PHP applicative ---
COPY php.ini /usr/local/etc/php/conf.d/99-app.ini

WORKDIR /var/www/html

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# --- Stockage des fichiers téléversés ---
# Hors de /var/www/html, donc hors du code monté et hors de la racine web.
# Créé ICI et confié à www-data : un volume nommé vide hérite, au premier
# montage, du propriétaire du dossier de l'image. Sans cela, il appartiendrait
# à root et PHP ne pourrait rien y écrire.
RUN mkdir -p /var/www/storage \
    && chown www-data:www-data /var/www/storage \
    && chmod 0750 /var/www/storage

# php-fpm tourne en www-data (UID 82 sur Alpine)
USER www-data

EXPOSE 9000
# Les migrations sont jouées avant php-fpm : cf. entrypoint.sh.
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]
