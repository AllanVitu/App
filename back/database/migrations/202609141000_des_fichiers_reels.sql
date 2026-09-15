-- ============================================================================
--  Des fichiers réels
--
--  ---------------------------------------------------------------------------
--  DEUX PROMESSES CREUSES
--
--  Le module Design annonçait des « fichiers » et n'en stockait aucun : une
--  version était un intitulé et une note. L'avatar, lui, était une URL tapée
--  à la main — et chaque affichage envoyait l'adresse IP de tous les
--  coéquipiers au site qui hébergeait l'image, sans que personne l'ait choisi.
--
--  ---------------------------------------------------------------------------
--  UNE TABLE POUR LES DESCRIPTIONS, UN VOLUME POUR LES OCTETS
--
--  Les octets ne vont pas en base. Un BYTEA de dix mégaoctets alourdit chaque
--  sauvegarde, chaque réplication et chaque VACUUM pour un contenu qui ne se
--  requête jamais. Ils vivent dans un volume hors de la racine web ; la base
--  garde ce qui se requête — à qui, quel type, quelle taille, quelle empreinte.
--
--  ---------------------------------------------------------------------------
--  LE PIÈGE DES CASCADES, ET SA PARADE
--
--  Supprimer un compte ou un espace emporte ses lignes par ON DELETE CASCADE,
--  et aucun code PHP ne s'exécute pendant une cascade. Les fichiers resteraient
--  sur le disque, orphelins — et avec eux la photo de quelqu'un qui a demandé
--  l'effacement de son compte.
--
--  Un déclencheur consigne donc chaque clé de stockage supprimée, QUEL QUE SOIT
--  le chemin de la suppression : route, cascade, ou psql à la main. Le worker
--  efface ensuite les octets correspondants.
-- ============================================================================


-- ---------------------------------------------------------------------------
-- 1. Ce qui décrit un fichier
-- ---------------------------------------------------------------------------
CREATE TABLE stored_files (
    id              UUID         PRIMARY KEY DEFAULT gen_random_uuid(),

    -- À qui appartient le fichier : un ESPACE (une maquette) ou une PERSONNE
    -- (un avatar) — jamais les deux, jamais aucun. La contrainte en fin de
    -- table le rend impossible à rompre, même à la main.
    purpose         VARCHAR(16)  NOT NULL,
    organization_id UUID         REFERENCES organizations (id) ON DELETE CASCADE,
    owner_user_id   UUID         REFERENCES users (id) ON DELETE CASCADE,

    -- Chemin relatif dans le volume, TIRÉ AU SORT. Jamais dérivé du nom envoyé,
    -- qui peut contenir « ../ » ; le format est vérifié ici ET avant chaque
    -- accès au disque.
    storage_key     VARCHAR(80)  NOT NULL,

    -- Le type LU dans les octets, pas celui qu'annonçait le navigateur.
    media_type      VARCHAR(40)  NOT NULL,
    byte_size       INTEGER      NOT NULL,
    sha256          CHAR(64)     NOT NULL,
    width           INTEGER,
    height          INTEGER,

    -- Ne sert qu'à proposer un nom au téléchargement. Nettoyé avant d'arriver.
    original_name   VARCHAR(160),

    created_by      UUID         REFERENCES users (id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT stored_files_key_unique    UNIQUE (storage_key),
    CONSTRAINT stored_files_key_format    CHECK (storage_key ~ '^[a-f0-9]{2}/[a-f0-9]{2}/[a-f0-9]{32}$'),
    CONSTRAINT stored_files_size_positive CHECK (byte_size > 0),
    CONSTRAINT stored_files_owner         CHECK (
        (purpose = 'design' AND organization_id IS NOT NULL AND owner_user_id IS NULL)
     OR (purpose = 'avatar' AND owner_user_id IS NOT NULL AND organization_id IS NULL)
    )
);

-- La somme par espace est lue à chaque téléversement (le quota).
CREATE INDEX stored_files_organization_idx
    ON stored_files (organization_id)
    WHERE organization_id IS NOT NULL;

CREATE INDEX stored_files_owner_idx
    ON stored_files (owner_user_id)
    WHERE owner_user_id IS NOT NULL;

COMMENT ON TABLE stored_files IS
    'Description des fichiers téléversés — les octets vivent dans le volume de stockage, jamais en base';


-- ---------------------------------------------------------------------------
-- 2. Les pierres tombales
-- ---------------------------------------------------------------------------
CREATE TABLE stored_file_tombstones (
    storage_key VARCHAR(80) PRIMARY KEY,
    buried_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE stored_file_tombstones IS
    'Clés de fichiers supprimés dont les octets restent à effacer du volume (cf. FileStorage::purge)';

CREATE OR REPLACE FUNCTION bury_stored_file()
RETURNS TRIGGER AS $fn$
BEGIN
    INSERT INTO stored_file_tombstones (storage_key)
    VALUES (OLD.storage_key)
    ON CONFLICT DO NOTHING;

    RETURN OLD;
END;
$fn$ LANGUAGE plpgsql;

CREATE TRIGGER stored_files_bury
    AFTER DELETE ON stored_files
    FOR EACH ROW EXECUTE FUNCTION bury_stored_file();


-- ---------------------------------------------------------------------------
-- 3. Qui s'en sert
-- ---------------------------------------------------------------------------
--
-- SET NULL des deux côtés : un fichier disparu ne doit emporter ni la version
-- qui le montrait — son intitulé et sa note restent l'historique —, ni le
-- compte qui l'avait en photo.
ALTER TABLE design_versions
    ADD COLUMN asset_id UUID REFERENCES stored_files (id) ON DELETE SET NULL;

ALTER TABLE users
    ADD COLUMN avatar_file_id UUID REFERENCES stored_files (id) ON DELETE SET NULL;

-- L'URL libre disparaît AVEC la fonctionnalité, pas seulement de l'écran. Une
-- colonne qu'on n'écrit plus mais qu'on garde finit relue par un code qui
-- l'aurait oubliée, et les adresses qu'elle contient désignent des sites tiers
-- qu'aucun navigateur de l'équipe ne doit plus contacter.
ALTER TABLE users DROP COLUMN avatar_url;


-- ---------------------------------------------------------------------------
-- 4. Le ramassage
-- ---------------------------------------------------------------------------
--
-- Toutes les heures. Les suppressions explicites déposent en plus une tâche
-- immédiate : une photo retirée ne doit pas attendre la prochaine heure pour
-- quitter le disque.
INSERT INTO scheduled_tasks (name, type, payload, interval_seconds)
VALUES ('purge-fichiers', 'storage.purge', '{}'::jsonb, 3600);
