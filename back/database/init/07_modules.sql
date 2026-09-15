-- ============================================================================
--  Modèles métier des quatre modules restants
--
--  Même principe que 06_tickets.sql : chaque module quitte la table générique
--  module_items pour une structure qui dit ce qu'elle contient. Un JSONB sait
--  tout stocker, mais ne sait rien contraindre — ni interdire un statut
--  inventé, ni indexer une recherche, ni garantir qu'un déploiement terminé
--  porte une date de fin.
--
--  Les invariants restent tenus par la BASE : numérotation des versions,
--  dates de fin, agrégats d'erreurs. Une insertion manuelle en psql produit
--  donc une ligne aussi correcte qu'un passage par l'API.
-- ============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- Types énumérés
--
-- L'ORDRE DE DÉCLARATION EST SIGNIFIANT partout où l'on trie dessus :
-- PostgreSQL ordonne un type énuméré selon sa déclaration.
-- ---------------------------------------------------------------------------
CREATE TYPE deployment_env    AS ENUM ('preview', 'production');
CREATE TYPE deployment_status AS ENUM ('queued', 'building', 'ready', 'error', 'canceled');

CREATE TYPE error_level  AS ENUM ('warning', 'error', 'fatal');
CREATE TYPE error_status AS ENUM ('unresolved', 'resolved', 'ignored');

CREATE TYPE design_kind   AS ENUM ('maquette', 'prototype', 'systeme');
CREATE TYPE api_key_scope AS ENUM ('anon', 'service');


-- ===========================================================================
--  MODULE « backend » — tables de données et clés d'API
-- ===========================================================================

-- Une « table » au sens du module : la description d'un schéma que
-- l'utilisateur conçoit. Ce n'est PAS une table PostgreSQL réelle — le module
-- sert à dessiner un modèle, pas à exécuter du DDL au nom de l'utilisateur,
-- ce qui serait une porte ouverte sur la base de l'application.
CREATE TABLE backend_tables (
    id           UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id      UUID         NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name         VARCHAR(63)  NOT NULL,
    description  TEXT,

    -- Les colonnes SONT une liste ordonnée d'objets, pas des lignes : leur
    -- ordre a un sens, elles n'existent jamais hors de leur table et ne sont
    -- jamais interrogées séparément. Un JSONB est ici le bon outil, là où il
    -- ne l'était pas pour porter un module entier.
    columns      JSONB        NOT NULL DEFAULT '[]'::jsonb,

    -- Sécurité au niveau ligne : l'information la plus importante du module,
    -- donc une colonne à part et non une clé perdue dans le JSONB.
    rls_enabled  BOOLEAN      NOT NULL DEFAULT TRUE,
    row_estimate INTEGER      NOT NULL DEFAULT 0,

    created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    deleted_at   TIMESTAMPTZ,

    CONSTRAINT backend_tables_name_len    CHECK (char_length(btrim(name)) >= 1),
    -- Nom d'identifiant SQL : lettres, chiffres et soulignés, sans commencer
    -- par un chiffre. La contrainte vit ici plutôt que dans l'application,
    -- pour que la règle tienne quelle que soit la voie d'écriture.
    CONSTRAINT backend_tables_name_format CHECK (name ~ '^[a-z_][a-z0-9_]*$'),
    CONSTRAINT backend_tables_columns_arr CHECK (jsonb_typeof(columns) = 'array'),
    CONSTRAINT backend_tables_rows_sane   CHECK (row_estimate >= 0)
);

COMMENT ON TABLE  backend_tables         IS 'Schémas de données conçus dans le module Backend';
COMMENT ON COLUMN backend_tables.columns IS 'Liste ORDONNÉE de {name, type, nullable, default}';

-- Un nom de table est unique par compte, mais seulement parmi les VIVANTES :
-- l'index partiel autorise à recréer « users » après en avoir supprimé une.
CREATE UNIQUE INDEX backend_tables_name_unique
    ON backend_tables (user_id, name)
    WHERE deleted_at IS NULL;

CREATE INDEX backend_tables_listing_idx
    ON backend_tables (user_id, created_at DESC)
    WHERE deleted_at IS NULL;

CREATE TRIGGER backend_tables_set_updated_at
    BEFORE UPDATE ON backend_tables
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


-- Clés d'API.
--
-- La clé en clair n'est JAMAIS stockée : seuls son empreinte SHA-256 et son
-- préfixe le sont, exactement comme les jetons de rafraîchissement de
-- l'authentification (cf. 01_schema.sql). Le préfixe sert à reconnaître une
-- clé dans la liste ; l'empreinte sert à la vérifier. Une fuite de la base
-- ne livre donc aucune clé utilisable.
CREATE TABLE backend_api_keys (
    id           UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id      UUID          NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    label        VARCHAR(60)   NOT NULL,
    scope        api_key_scope NOT NULL DEFAULT 'anon',
    token_prefix VARCHAR(16)   NOT NULL,
    token_hash   TEXT          NOT NULL,
    last_used_at TIMESTAMPTZ,
    revoked_at   TIMESTAMPTZ,
    created_at   TIMESTAMPTZ   NOT NULL DEFAULT NOW(),

    CONSTRAINT backend_api_keys_label_len CHECK (char_length(btrim(label)) >= 1)
);

COMMENT ON TABLE  backend_api_keys            IS 'Clés d''API — seule l''empreinte est conservée';
COMMENT ON COLUMN backend_api_keys.token_hash IS 'SHA-256 de la clé ; la clé en clair n''existe qu''à sa création';

-- Une révocation n'efface pas la ligne : la trace de l'existence d'une clé
-- fait partie de ce qu'un journal de sécurité doit pouvoir montrer.
CREATE INDEX backend_api_keys_listing_idx ON backend_api_keys (user_id, created_at DESC);
CREATE UNIQUE INDEX backend_api_keys_hash_idx ON backend_api_keys (token_hash);


-- ===========================================================================
--  MODULE « deploiement » — déploiements liés à Git
-- ===========================================================================
CREATE TABLE deployments (
    id             UUID              PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id        UUID              NOT NULL REFERENCES users(id) ON DELETE CASCADE,

    environment    deployment_env    NOT NULL DEFAULT 'preview',
    branch         VARCHAR(120)      NOT NULL,
    commit_sha     VARCHAR(40)       NOT NULL,
    commit_message VARCHAR(200),
    status         deployment_status NOT NULL DEFAULT 'queued',
    url            TEXT,
    log            TEXT,

    -- Dérivés du statut par trigger, jamais écrits par l'application : deux
    -- sources pour un même fait finissent toujours par diverger.
    finished_at    TIMESTAMPTZ,
    duration_ms    INTEGER,

    created_at     TIMESTAMPTZ       NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ       NOT NULL DEFAULT NOW(),
    deleted_at     TIMESTAMPTZ,

    CONSTRAINT deployments_branch_len CHECK (char_length(btrim(branch)) >= 1),
    -- Une empreinte Git est hexadécimale, de la forme courte (7) à la forme
    -- complète (40). Un « sha » qui n'en est pas un rendrait le lien vers le
    -- dépôt inexploitable.
    CONSTRAINT deployments_sha_format CHECK (commit_sha ~ '^[0-9a-f]{7,40}$')
);

COMMENT ON TABLE  deployments             IS 'Déploiements du module Déploiement';
COMMENT ON COLUMN deployments.finished_at IS 'Dérivé du statut par trigger — ne pas écrire depuis l''application';

CREATE INDEX deployments_listing_idx
    ON deployments (user_id, created_at DESC)
    WHERE deleted_at IS NULL;

-- « Quel est le déploiement courant de la production ? » — la question la
-- plus fréquente du module, et la seule qui mérite son propre index.
CREATE INDEX deployments_env_idx
    ON deployments (user_id, environment, created_at DESC)
    WHERE deleted_at IS NULL;

-- ---------------------------------------------------------------------------
-- Trigger : date de fin et durée alignées sur le statut
--
-- Relancer un déploiement (retour à « queued ») efface sa date de fin ET sa
-- durée. Sans cet effacement, un déploiement relancé afficherait la durée de
-- sa tentative précédente, ce qui est pire qu'une durée absente.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION sync_deployment_finished_at()
RETURNS TRIGGER AS $fn$
BEGIN
    IF NEW.status IN ('ready', 'error', 'canceled') THEN
        IF NEW.finished_at IS NULL THEN
            NEW.finished_at = NOW();
        END IF;

        NEW.duration_ms = GREATEST(
            0,
            (EXTRACT(EPOCH FROM (NEW.finished_at - NEW.created_at)) * 1000)::INTEGER
        );
    ELSE
        NEW.finished_at = NULL;
        NEW.duration_ms = NULL;
    END IF;

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;

CREATE TRIGGER deployments_sync_finished_at
    BEFORE INSERT OR UPDATE OF status ON deployments
    FOR EACH ROW EXECUTE FUNCTION sync_deployment_finished_at();

CREATE TRIGGER deployments_set_updated_at
    BEFORE UPDATE ON deployments
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


-- ===========================================================================
--  MODULE « supervision » — erreurs de production, groupées
-- ===========================================================================

-- Le GROUPE est l'unité de travail, pas l'occurrence : mille fois la même
-- exception est un seul problème à corriger. C'est l'empreinte qui rassemble.
CREATE TABLE error_groups (
    id           UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id      UUID         NOT NULL REFERENCES users(id) ON DELETE CASCADE,

    -- Calculée à partir du type d'exception et du point d'origine : deux
    -- occurrences de même empreinte sont le même problème.
    fingerprint  VARCHAR(64)  NOT NULL,

    title        VARCHAR(200) NOT NULL,
    culprit      VARCHAR(200),
    level        error_level  NOT NULL DEFAULT 'error',
    status       error_status NOT NULL DEFAULT 'unresolved',

    -- Agrégats entretenus par trigger à chaque occurrence reçue.
    occurrences  INTEGER      NOT NULL DEFAULT 0,
    first_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_seen_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    deleted_at   TIMESTAMPTZ,

    CONSTRAINT error_groups_title_len   CHECK (char_length(btrim(title)) >= 1),
    CONSTRAINT error_groups_count_sane  CHECK (occurrences >= 0),
    CONSTRAINT error_groups_fingerprint_unique UNIQUE (user_id, fingerprint)
);

COMMENT ON TABLE  error_groups             IS 'Erreurs de production, regroupées par empreinte';
COMMENT ON COLUMN error_groups.occurrences IS 'Entretenu par trigger à l''insertion d''une occurrence';

CREATE INDEX error_groups_listing_idx
    ON error_groups (user_id, status, last_seen_at DESC)
    WHERE deleted_at IS NULL;

CREATE TRIGGER error_groups_set_updated_at
    BEFORE UPDATE ON error_groups
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


-- Une occurrence : ce que le serveur a réellement reçu.
CREATE TABLE error_events (
    id          UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    group_id    UUID        NOT NULL REFERENCES error_groups(id) ON DELETE CASCADE,
    -- Dupliqué depuis le groupe pour que TOUTE requête puisse filtrer sur
    -- user_id sans jointure : le cloisonnement ne doit jamais dépendre du
    -- soin apporté à écrire un JOIN.
    user_id     UUID        NOT NULL REFERENCES users(id) ON DELETE CASCADE,

    message     TEXT        NOT NULL,
    stack       TEXT,
    context     JSONB       NOT NULL DEFAULT '{}'::jsonb,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE error_events IS 'Occurrences individuelles rattachées à un groupe d''erreurs';

CREATE INDEX error_events_group_idx ON error_events (group_id, occurred_at DESC);
CREATE INDEX error_events_user_idx  ON error_events (user_id, occurred_at DESC);

-- ---------------------------------------------------------------------------
-- Trigger : les agrégats du groupe suivent ses occurrences
--
-- Compter les occurrences par « SELECT COUNT(*) » à chaque affichage
-- fonctionnerait, mais la liste des erreurs est l'écran le plus consulté du
-- module : l'agrégat est maintenu à l'écriture, qui est rare, plutôt qu'à la
-- lecture, qui est fréquente.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION bump_error_group()
RETURNS TRIGGER AS $fn$
BEGIN
    UPDATE error_groups
       SET occurrences  = occurrences + 1,
           last_seen_at = GREATEST(last_seen_at, NEW.occurred_at),
           -- Une erreur qui se reproduit n'est plus résolue. Le statut est
           -- donc rouvert automatiquement : laisser « résolu » sur un
           -- problème qui frappe encore est le pire des mensonges pour un
           -- outil de supervision. « Ignoré » reste ignoré, c'est une
           -- décision explicite de ne plus vouloir en entendre parler.
           status       = CASE WHEN status = 'resolved' THEN 'unresolved' ELSE status END
     WHERE id = NEW.group_id;

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;

CREATE TRIGGER error_events_bump_group
    AFTER INSERT ON error_events
    FOR EACH ROW EXECUTE FUNCTION bump_error_group();


-- ===========================================================================
--  MODULE « design » — fichiers et versions
-- ===========================================================================
CREATE TABLE design_files (
    id          UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id     UUID         NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name        VARCHAR(120) NOT NULL,
    kind        design_kind  NOT NULL DEFAULT 'maquette',
    description TEXT,

    -- Deux couleurs suffisent à rendre une vignette reconnaissable dans une
    -- grille, sans stocker d'image : le module documente un travail de
    -- design, il n'héberge pas les fichiers eux-mêmes.
    accent      VARCHAR(7)   NOT NULL DEFAULT '#7ee2a8',

    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    deleted_at  TIMESTAMPTZ,

    CONSTRAINT design_files_name_len     CHECK (char_length(btrim(name)) >= 1),
    CONSTRAINT design_files_accent_hex   CHECK (accent ~ '^#[0-9a-fA-F]{6}$')
);

COMMENT ON TABLE design_files IS 'Maquettes, prototypes et systèmes de composants';

CREATE INDEX design_files_listing_idx
    ON design_files (user_id, updated_at DESC)
    WHERE deleted_at IS NULL;

CREATE TRIGGER design_files_set_updated_at
    BEFORE UPDATE ON design_files
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


CREATE TABLE design_versions (
    id         UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    file_id    UUID         NOT NULL REFERENCES design_files(id) ON DELETE CASCADE,
    -- Même raison que pour error_events : le cloisonnement ne doit pas
    -- dépendre d'une jointure correctement écrite.
    user_id    UUID         NOT NULL REFERENCES users(id) ON DELETE CASCADE,

    -- Posé par trigger, par FICHIER : « v3 » n'a de sens que rapporté au
    -- fichier auquel il appartient.
    number     INTEGER      NOT NULL,
    label      VARCHAR(120),
    notes      TEXT,
    created_at TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT design_versions_number_unique   UNIQUE (file_id, number),
    CONSTRAINT design_versions_number_positive CHECK (number > 0)
);

COMMENT ON TABLE  design_versions        IS 'Historique des versions d''un fichier de design';
COMMENT ON COLUMN design_versions.number IS 'Séquentiel PAR FICHIER, attribué par trigger';

CREATE INDEX design_versions_file_idx ON design_versions (file_id, number DESC);

-- ---------------------------------------------------------------------------
-- Trigger : numérotation des versions, par fichier
--
-- « MAX(number)+1 » suffirait rarement : deux enregistrements simultanés
-- liraient le même maximum. Le verrou posé sur la ligne du fichier par
-- FOR UPDATE sérialise les créations de versions d'un MÊME fichier, sans
-- gêner celles des autres.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION assign_design_version_number()
RETURNS TRIGGER AS $fn$
DECLARE
    next_number INTEGER;
BEGIN
    IF NEW.number IS NOT NULL THEN
        RETURN NEW;
    END IF;

    PERFORM 1 FROM design_files WHERE id = NEW.file_id FOR UPDATE;

    SELECT COALESCE(MAX(number), 0) + 1
      INTO next_number
      FROM design_versions
     WHERE file_id = NEW.file_id;

    NEW.number = next_number;

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;

CREATE TRIGGER design_versions_assign_number
    BEFORE INSERT ON design_versions
    FOR EACH ROW EXECUTE FUNCTION assign_design_version_number();

COMMIT;
