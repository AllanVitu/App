#!/bin/sh
# Sain tant que la dernière sauvegarde RÉUSSIE a moins de deux intervalles.
limite=$(( ${BACKUP_INTERVAL_SECONDS:-86400} * 2 / 60 ))

[ -n "$(find "${BACKUP_DIR:-/sauvegardes}/.derniere-reussite" -mmin -"$limite" 2>/dev/null)" ]
