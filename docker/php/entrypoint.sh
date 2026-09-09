#!/bin/sh
# =============================================================================
#  Démarrage du conteneur PHP
#
#  Les migrations sont jouées AVANT php-fpm, pour la même raison que les
#  scripts d'initialisation le sont avant tout le reste : l'application ne doit
#  jamais servir une requête sur un schéma qu'elle ne connaît pas.
#
#  Le service attend déjà que PostgreSQL soit sain (« condition:
#  service_healthy » dans docker-compose) : aucune attente à refaire ici.
#
#  Plusieurs conteneurs qui démarrent ensemble ne se marchent pas dessus — le
#  migrateur prend un verrou consultatif PostgreSQL, le second attend puis
#  constate qu'il n'y a rien à faire.
#
#  DB_AUTO_MIGRATE=false pour reprendre la main : la migration se joue alors
#  explicitement, par « docker compose exec php composer migrate ».
# =============================================================================
set -e

if [ "${DB_AUTO_MIGRATE:-true}" = "true" ]; then
    php /var/www/html/bin/migrate.php
fi

exec "$@"
