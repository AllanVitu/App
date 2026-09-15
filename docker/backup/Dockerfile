# Sauvegardes de Relais : la base ET les fichiers, ensemble, à intervalle régulier.
#
# Même version majeure que la base : pg_dump doit être au moins de la version
# du serveur qu'il interroge, sinon il refuse de travailler.
FROM postgres:16-alpine

ENV BACKUP_DIR=/sauvegardes \
    STORAGE_DIR=/stockage \
    RESTORE_STORAGE_DIR=/restauration \
    BACKUP_INTERVAL_SECONDS=86400 \
    BACKUP_RETENTION_DAYS=14

COPY docker/backup/sauvegarder.sh docker/backup/restaurer.sh docker/backup/sante.sh /usr/local/bin/

RUN chmod 0755 /usr/local/bin/sauvegarder.sh /usr/local/bin/restaurer.sh /usr/local/bin/sante.sh \
    && mkdir -p /sauvegardes \
    && chmod 0700 /sauvegardes

# Sain tant que la dernière sauvegarde RÉUSSIE a moins de deux intervalles : un
# conteneur qui tourne sans rien sauvegarder doit se voir dans « docker ps ».
HEALTHCHECK --interval=5m --timeout=10s --start-period=15m CMD sante.sh

CMD ["sauvegarder.sh"]
