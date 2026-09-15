#!/bin/sh
# ============================================================================
#  Restaure une sauvegarde de Relais : la base et les fichiers du même instant.
#
#  ÉCRASE la base désignée par PGDATABASE et, si un volume est monté sur
#  RESTORE_STORAGE_DIR, les fichiers qu'il contient. Arrêter php et le worker
#  avant, les relancer après : cf. README, « Sauvegardes ».
#
#  Rien ne se passe sans CONFIRMER=oui : une restauration lancée par mégarde
#  effacerait tout ce qui a été fait depuis la sauvegarde.
# ============================================================================
set -eu

DOSSIER="${BACKUP_DIR:-/sauvegardes}"
CIBLE="${RESTORE_STORAGE_DIR:-/restauration}"
horodatage="${1:-}"

if [ -z "$horodatage" ]; then
    echo "Usage : restaurer.sh <horodatage>. Horodatages disponibles :"
    ls "$DOSSIER" | grep -- '-base[.]dump$' | sed 's/^relais-/  /; s/-base[.]dump$//' || echo "  (aucun)"
    exit 2
fi

base="$DOSSIER/relais-$horodatage-base.dump"
archive="$DOSSIER/relais-$horodatage-fichiers.tar.gz"

if [ ! -f "$base" ] || [ ! -f "$archive" ]; then
    echo "Sauvegarde introuvable ou incomplète pour « $horodatage »." >&2
    exit 2
fi

if [ "${CONFIRMER:-}" != "oui" ]; then
    echo "Restaurer ÉCRASE la base « $PGDATABASE » et les fichiers. Relancez avec -e CONFIRMER=oui." >&2
    exit 3
fi

echo "Base « $PGDATABASE » : restauration depuis $(basename "$base")…"
pg_restore --clean --if-exists --no-owner --single-transaction --exit-on-error --dbname="$PGDATABASE" "$base"

if [ -d "$CIBLE" ]; then
    echo "Fichiers : restauration dans $CIBLE…"
    find "$CIBLE" -mindepth 1 -delete
    tar -xzf "$archive" -C "$CIBLE"
else
    echo "Aucun volume monté sur $CIBLE : fichiers non restaurés (cf. README, « Sauvegardes »)."
fi

echo "Restauration terminée."
