#!/bin/sh
# ============================================================================
#  Sauvegarde de Relais : la base et les fichiers, ensemble.
#
#  Au démarrage du conteneur, puis toutes les BACKUP_INTERVAL_SECONDS :
#
#   1. la BASE, par pg_dump au format personnalisé (compressé, restaurable
#      table par table), tous schémas compris — les tables Backend des
#      espaces vivent dans des schémas à part ;
#   2. les FICHIERS téléversés, APRÈS la base : un fichier décrit par la base
#      est donc toujours dans l'archive (la purge n'efface du disque que ce que
#      la base a déjà oublié) ;
#   3. la ROTATION : ce qui a plus de BACKUP_RETENTION_DAYS jours est effacé.
#
#  Une archive est écrite sous un nom provisoire, RELUE, puis renommée : une
#  sauvegarde interrompue ou illisible ne se fait jamais passer pour complète.
#  Un échec est journalisé, et la dernière sauvegarde valide reste en place.
# ============================================================================

DOSSIER="${BACKUP_DIR:-/sauvegardes}"
FICHIERS="${STORAGE_DIR:-/stockage}"
INTERVALLE="${BACKUP_INTERVAL_SECONDS:-86400}"
RETENTION="${BACKUP_RETENTION_DAYS:-14}"

# Les archives contiennent toutes les données personnelles de l'instance :
# lisibles par leur seul propriétaire.
umask 077

journal() {
    printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

sauvegarder() {
    horodatage="$(date -u +%Y%m%dT%H%M%SZ)"
    base="$DOSSIER/relais-$horodatage-base.dump"
    archive="$DOSSIER/relais-$horodatage-fichiers.tar.gz"

    pg_dump --format=custom --compress=6 --no-owner --file="$base.partiel" || return 1
    pg_restore --list "$base.partiel" > /dev/null || return 1
    mv "$base.partiel" "$base" || return 1

    tar -czf "$archive.partiel" -C "$FICHIERS" . || return 1
    tar -tzf "$archive.partiel" > /dev/null || return 1
    mv "$archive.partiel" "$archive" || return 1

    touch "$DOSSIER/.derniere-reussite"
    journal "sauvegarde réussie : $(basename "$base") ($(du -h "$base" | cut -f1)), $(basename "$archive") ($(du -h "$archive" | cut -f1))"

    find "$DOSSIER" -maxdepth 1 -type f -name 'relais-*' -mtime +"$RETENTION" -exec rm -f {} +
}

journal "sauvegardes dans $DOSSIER : toutes les $INTERVALLE s, conservées $RETENTION jours"

until pg_isready --quiet; do
    journal "base injoignable, nouvel essai dans 5 s"
    sleep 5
done

while true; do
    if ! sauvegarder; then
        journal "ÉCHEC de la sauvegarde — la précédente sauvegarde valide reste en place"
        rm -f "$DOSSIER"/*.partiel
    fi

    sleep "$INTERVALLE"
done
