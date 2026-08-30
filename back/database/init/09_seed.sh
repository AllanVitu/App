#!/bin/sh
# ============================================================================
#  Chargement du jeu de données, choisi par la variable DB_SEED.
#
#    DB_SEED=dev.sql   (défaut) -> compte de démonstration
#    DB_SEED=none.sql           -> aucune donnée de démonstration (déploiement)
#
#  Les fichiers vivent dans back/database/seeds/, monté en lecture seule sur
#  /seeds : le jeu de démonstration n'est donc JAMAIS chargé implicitement du
#  seul fait de sa présence dans le dépôt.
#
#  Exécuté une seule fois, à la création du volume PostgreSQL.
#
#  PRÉFIXE 09 : PostgreSQL exécute ce dossier dans l'ordre des noms, et ce
#  script insère des DONNÉES — il doit donc passer après TOUTE la structure.
#  Numéroté 03, il précédait 05_terms.sql et insérait « terms_accepted_at »
#  dans une table qui n'avait pas encore la colonne : sur un volume neuf, le
#  chargement échouait. Tout nouveau fichier de structure se numérote donc
#  entre 01 et 08.
# ============================================================================

SEED_FILE="/seeds/${DB_SEED:-dev.sql}"

if [ ! -f "$SEED_FILE" ]; then
    echo "[seed] ERREUR : jeu de données introuvable ($SEED_FILE)." >&2
    echo "[seed] Valeurs attendues pour DB_SEED : dev.sql ou none.sql." >&2
    exit 1
fi

echo "[seed] Chargement de $SEED_FILE (APP_ENV=${APP_ENV:-development})"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" -f "$SEED_FILE"
