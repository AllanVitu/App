-- ============================================================================
--  SaaS Starter — Schéma d'initialisation PostgreSQL 16
--
--  Exécuté automatiquement par le conteneur "db" à la création du volume.
--  Modèle : mono-utilisateur (chaque compte possède ses données via user_id).
--  Les modules sont un REGISTRE extensible : ajouter un module = insérer une
--  ligne dans "modules", sans migration de structure.
--
--  Conventions :
--   - Clés primaires UUID (non énumérables dans les URLs de l'API).
--   - Horodatages en TIMESTAMPTZ (UTC), jamais en TIMESTAMP nu.
--   - snake_case, tables au pluriel.
-- ============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- Extensions
-- ---------------------------------------------------------------------------
-- citext : type texte insensible à la casse -> unicité réelle des e-mails
-- (évite que "Jean@Mail.com" et "jean@mail.com" créent deux comptes).
CREATE EXTENSION IF NOT EXISTS citext;

-- Note : gen_random_uuid() est natif depuis PostgreSQL 13, aucune extension requise.


-- ---------------------------------------------------------------------------
-- Types énumérés
-- ---------------------------------------------------------------------------
CREATE TYPE user_role   AS ENUM ('user', 'admin');
CREATE TYPE item_status AS ENUM ('draft', 'active', 'archived');


-- ---------------------------------------------------------------------------
-- Fonction utilitaire : met à jour updated_at à chaque UPDATE.
-- Centralisée ici pour ne pas dupliquer la logique dans l'application.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $fn$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;


-- ===========================================================================
--  1. users — comptes applicatifs
-- ===========================================================================
CREATE TABLE users (
    id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    email             CITEXT       NOT NULL,
    -- Hash bcrypt produit par password_hash() côté PHP.
    -- Jamais de mot de passe en clair, jamais de MD5/SHA1.
    password_hash     TEXT         NOT NULL,
    full_name         VARCHAR(120) NOT NULL,
    avatar_url        TEXT,
    role              user_role    NOT NULL DEFAULT 'user',
    is_active         BOOLEAN      NOT NULL DEFAULT TRUE,
    email_verified_at TIMESTAMPTZ,
    last_login_at     TIMESTAMPTZ,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT users_email_unique  UNIQUE (email),
    -- Garde-fou basique : la validation fine reste côté PHP.
    CONSTRAINT users_email_format  CHECK (email ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'),
    CONSTRAINT users_full_name_len CHECK (char_length(btrim(full_name)) >= 2)
);

COMMENT ON TABLE  users               IS 'Comptes utilisateurs de l''application';
COMMENT ON COLUMN users.password_hash IS 'Hash password_hash() PHP (bcrypt) — non réversible';

CREATE INDEX users_active_idx     ON users (is_active) WHERE is_active;
CREATE INDEX users_created_at_idx ON users (created_at DESC);

CREATE TRIGGER users_set_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


-- ===========================================================================
--  2. refresh_tokens — sessions longues (rotation des JWT, Étape 3)
--
--  Le token brut n'est JAMAIS stocké : seul son SHA-256 l'est. Une fuite de
--  la base ne permet donc pas de rejouer les sessions.
-- ===========================================================================
CREATE TABLE refresh_tokens (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id     UUID        NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  CHAR(64)    NOT NULL,          -- SHA-256 en hexadécimal
    expires_at  TIMESTAMPTZ NOT NULL,
    revoked_at  TIMESTAMPTZ,                   -- NULL = jeton toujours valide
    user_agent  TEXT,                          -- traçabilité des appareils
    ip_address  INET,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT refresh_tokens_hash_unique UNIQUE (token_hash)
);

COMMENT ON TABLE refresh_tokens IS 'Jetons de rafraîchissement — stockés hachés (SHA-256)';

-- Index partiel : les requêtes ne ciblent que les jetons encore actifs.
CREATE INDEX refresh_tokens_active_idx
    ON refresh_tokens (user_id, expires_at)
    WHERE revoked_at IS NULL;


-- ===========================================================================
--  3. login_attempts — anti-bruteforce (limitation de débit, Étape 3)
-- ===========================================================================
CREATE TABLE login_attempts (
    id           BIGSERIAL PRIMARY KEY,
    email        CITEXT      NOT NULL,   -- tentative : le compte peut ne pas exister
    ip_address   INET,
    successful   BOOLEAN     NOT NULL DEFAULT FALSE,
    attempted_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE login_attempts IS 'Journal des tentatives de connexion — sert au throttling';

CREATE INDEX login_attempts_email_idx ON login_attempts (email, attempted_at DESC);
CREATE INDEX login_attempts_ip_idx    ON login_attempts (ip_address, attempted_at DESC);


-- ===========================================================================
--  4. modules — registre des modules SaaS
--
--  Ajouter un module = 1 INSERT. Le front construit sa navigation à partir
--  de cette table : aucun menu codé en dur.
-- ===========================================================================
CREATE TABLE modules (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    slug        VARCHAR(60)  NOT NULL,   -- identifiant d'URL : /modules/module-1
    name        VARCHAR(100) NOT NULL,
    description TEXT,
    icon        VARCHAR(60),             -- nom d'icône exploité par le front
    position    SMALLINT     NOT NULL DEFAULT 0,   -- ordre d'affichage
    is_active   BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT modules_slug_unique UNIQUE (slug),
    CONSTRAINT modules_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')
);

COMMENT ON TABLE modules IS 'Catalogue des modules — pilote la navigation du front';

CREATE INDEX modules_listing_idx ON modules (position, name) WHERE is_active;

CREATE TRIGGER modules_set_updated_at
    BEFORE UPDATE ON modules
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


-- ===========================================================================
--  5. user_modules — accès et préférences par utilisateur et par module
--
--  Permet d'activer/désactiver un module par compte (base d'un futur système
--  d'offres) et de stocker des réglages propres au module.
-- ===========================================================================
CREATE TABLE user_modules (
    user_id     UUID        NOT NULL REFERENCES users(id)   ON DELETE CASCADE,
    module_id   UUID        NOT NULL REFERENCES modules(id) ON DELETE CASCADE,
    is_enabled  BOOLEAN     NOT NULL DEFAULT TRUE,
    settings    JSONB       NOT NULL DEFAULT '{}'::jsonb,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    PRIMARY KEY (user_id, module_id)
);

COMMENT ON TABLE user_modules IS 'Droits d''accès et réglages d''un module pour un utilisateur';

CREATE INDEX user_modules_enabled_idx ON user_modules (user_id) WHERE is_enabled;

CREATE TRIGGER user_modules_set_updated_at
    BEFORE UPDATE ON user_modules
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


-- ===========================================================================
--  6. module_items — contenu générique des modules
--
--  Colonnes communes (titre, statut, dates) pour les besoins transverses
--  — tri, recherche, pagination — plus un champ JSONB "data" pour les champs
--  spécifiques à chaque module, sans migration de schéma.
-- ===========================================================================
CREATE TABLE module_items (
    id          UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    module_id   UUID         NOT NULL REFERENCES modules(id) ON DELETE CASCADE,
    user_id     UUID         NOT NULL REFERENCES users(id)   ON DELETE CASCADE,
    title       VARCHAR(200) NOT NULL,
    description TEXT,
    status      item_status  NOT NULL DEFAULT 'draft',
    data        JSONB        NOT NULL DEFAULT '{}'::jsonb,  -- charge utile métier
    position    INTEGER      NOT NULL DEFAULT 0,
    due_date    DATE,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    deleted_at  TIMESTAMPTZ,                                -- suppression logique

    CONSTRAINT module_items_title_len CHECK (char_length(btrim(title)) >= 1)
);

COMMENT ON TABLE  module_items            IS 'Enregistrements d''un module (structure générique)';
COMMENT ON COLUMN module_items.data       IS 'Champs spécifiques au module, en JSONB';
COMMENT ON COLUMN module_items.deleted_at IS 'Suppression logique : NULL = visible';

-- Index principal des listings : « les items du module X pour l'utilisateur Y ».
-- Partiel sur deleted_at IS NULL : l'index ignore la corbeille.
CREATE INDEX module_items_listing_idx
    ON module_items (user_id, module_id, created_at DESC)
    WHERE deleted_at IS NULL;

CREATE INDEX module_items_status_idx
    ON module_items (user_id, status)
    WHERE deleted_at IS NULL;

-- GIN : interrogation efficace du JSONB (data @> '{"priority":"high"}')
CREATE INDEX module_items_data_gin_idx ON module_items USING GIN (data jsonb_path_ops);

CREATE TRIGGER module_items_set_updated_at
    BEFORE UPDATE ON module_items
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


-- ===========================================================================
--  7. user_settings — page Paramètres (1 ligne par utilisateur)
-- ===========================================================================
CREATE TABLE user_settings (
    user_id       UUID        PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    theme         VARCHAR(10) NOT NULL DEFAULT 'system',
    language      VARCHAR(5)  NOT NULL DEFAULT 'fr',
    timezone      VARCHAR(64) NOT NULL DEFAULT 'Europe/Paris',
    notifications JSONB       NOT NULL DEFAULT
                  '{"email": true, "push": false, "weekly_digest": true}'::jsonb,
    preferences   JSONB       NOT NULL DEFAULT '{}'::jsonb,  -- extension libre
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT user_settings_theme_valid CHECK (theme IN ('light', 'dark', 'system'))
);

COMMENT ON TABLE user_settings IS 'Préférences utilisateur — alimente la page Paramètres';

CREATE TRIGGER user_settings_set_updated_at
    BEFORE UPDATE ON user_settings
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


-- ===========================================================================
--  8. Provisionnement automatique à l'inscription
--
--  À la création d'un utilisateur : ses préférences par défaut sont créées et
--  tous les modules actifs lui sont attribués. L'API n'a donc rien à
--  orchestrer, et la cohérence tient même en cas d'insertion manuelle.
-- ===========================================================================
CREATE OR REPLACE FUNCTION provision_new_user()
RETURNS TRIGGER AS $fn$
BEGIN
    INSERT INTO user_settings (user_id) VALUES (NEW.id);

    INSERT INTO user_modules (user_id, module_id)
    SELECT NEW.id, m.id FROM modules m WHERE m.is_active;

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;

CREATE TRIGGER users_provision_defaults
    AFTER INSERT ON users
    FOR EACH ROW EXECUTE FUNCTION provision_new_user();

COMMIT;
