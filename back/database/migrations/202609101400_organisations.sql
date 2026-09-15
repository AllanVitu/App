-- ============================================================================
--  Organisations, appartenances, invitations
--
--  ---------------------------------------------------------------------------
--  L'APPLICATION ÉTAIT UN SaaS À UN SEUL JOUEUR
--
--  Un compte égalait un espace de travail. Aucune table d'organisation,
--  d'équipe ni d'invitation ; le cloisonnement des données reposait entièrement
--  sur « user_id », présent sur quatorze tables.
--
--  ---------------------------------------------------------------------------
--  CETTE MIGRATION N'EST QU'ADDITIVE, ET C'EST VOULU
--
--  Elle crée les trois tables et rattache l'existant. Elle NE TOUCHE PAS au
--  cloisonnement : celui-ci reste porté par « user_id » jusqu'à la migration
--  suivante, qui pose « organization_id » sur les tables métier.
--
--  Deux migrations plutôt qu'une, parce que chacune se vérifie seule : après
--  celle-ci, chaque compte a son organisation et l'application se comporte
--  exactement comme avant. C'est le point de contrôle qui rend la seconde
--  sûre.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. organizations — l'espace de travail
-- ---------------------------------------------------------------------------
CREATE TABLE organizations (
    id         UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    name       VARCHAR(120) NOT NULL,

    -- CITEXT comme pour les e-mails : « Acme » et « acme » ne peuvent pas
    -- coexister. L'unicité doit être RÉELLE, pas sensible à la casse.
    slug       CITEXT       NOT NULL,

    created_at TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT organizations_slug_unique UNIQUE (slug),
    CONSTRAINT organizations_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    CONSTRAINT organizations_name_len    CHECK (char_length(btrim(name)) >= 2)
);

COMMENT ON TABLE  organizations      IS 'Espace de travail : ce qui possède les données, à la place du compte';
COMMENT ON COLUMN organizations.slug IS 'Identifiant lisible et stable, utilisable dans une URL';

CREATE TRIGGER organizations_set_updated_at
    BEFORE UPDATE ON organizations
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


-- ---------------------------------------------------------------------------
-- 2. memberships — qui appartient à quoi, et à quel titre
-- ---------------------------------------------------------------------------
--
-- TROIS RÔLES, chacun répondant à une question précise :
--
--   owner   — peut supprimer l'organisation et transmettre ce rôle. Il y en a
--             toujours au moins un ; la règle est tenue par l'application, qui
--             refuse de retirer le dernier.
--   admin   — invite, exclut, change les rôles. Ne peut pas supprimer
--             l'organisation.
--   member  — travaille. C'est le rôle par défaut d'une invitation.
--
-- Le « role » de la table « users » demeure et garde son sens d'origine :
-- administrateur de l'INSTANCE, pas de l'organisation. Les deux se ressemblent
-- assez pour qu'il faille le dire ici.
CREATE TYPE membership_role AS ENUM ('owner', 'admin', 'member');

CREATE TABLE memberships (
    organization_id UUID            NOT NULL REFERENCES organizations (id) ON DELETE CASCADE,
    user_id         UUID            NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    role            membership_role NOT NULL DEFAULT 'member',
    created_at      TIMESTAMPTZ     NOT NULL DEFAULT NOW(),

    -- La clé composée EST la règle : on n'appartient qu'une fois à une
    -- organisation donnée. Aucun code applicatif n'a à s'en assurer.
    PRIMARY KEY (organization_id, user_id)
);

COMMENT ON TABLE memberships IS 'Appartenance d''un compte à une organisation, avec son rôle';

-- La clé primaire couvre déjà « les membres de cette organisation ». Cet index
-- couvre la question inverse — « les organisations de ce compte » — celle que
-- pose le sélecteur d'espace de travail à chaque chargement.
CREATE INDEX memberships_user_idx ON memberships (user_id);


-- ---------------------------------------------------------------------------
-- 3. invitations — faire entrer quelqu'un qui n'a pas encore de compte
-- ---------------------------------------------------------------------------
--
-- Même modèle que la confirmation d'adresse : une valeur aléatoire envoyée par
-- e-mail dont la base ne garde QUE l'empreinte SHA-256. Une fuite de la table
-- ne permet donc pas de rejouer une invitation.
--
-- L'invitation porte l'ADRESSE et non un identifiant d'utilisateur : on invite
-- des gens qui n'ont pas de compte, et c'est même le cas le plus courant.
CREATE TABLE invitations (
    id              UUID            PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id UUID            NOT NULL REFERENCES organizations (id) ON DELETE CASCADE,
    email           CITEXT          NOT NULL,
    role            membership_role NOT NULL DEFAULT 'member',
    token_hash      CHAR(64)        NOT NULL,

    -- « SET NULL » et non « CASCADE » : le départ de celui qui a invité ne doit
    -- pas annuler l'invitation de celui qui arrive.
    invited_by      UUID            REFERENCES users (id) ON DELETE SET NULL,

    expires_at      TIMESTAMPTZ     NOT NULL,
    accepted_at     TIMESTAMPTZ,
    created_at      TIMESTAMPTZ     NOT NULL DEFAULT NOW(),

    CONSTRAINT invitations_token_unique UNIQUE (token_hash)
);

COMMENT ON COLUMN invitations.token_hash IS 'SHA-256 du jeton envoyé par e-mail — le jeton lui-même n''est jamais stocké';

-- Une seule invitation EN COURS par adresse et par organisation. L'index est
-- partiel : une invitation acceptée ne bloque pas une nouvelle invitation, ce
-- qui permet de réinviter quelqu'un qui était parti.
CREATE UNIQUE INDEX invitations_en_cours_idx
    ON invitations (organization_id, email)
 WHERE accepted_at IS NULL;

-- « qu'est-ce qui m'attend ? », posée à la connexion et juste après une
-- inscription.
CREATE INDEX invitations_email_idx ON invitations (email) WHERE accepted_at IS NULL;


-- ---------------------------------------------------------------------------
-- 4. Reprise de l'existant
-- ---------------------------------------------------------------------------
--
-- Chaque compte reçoit SON organisation, dont il est propriétaire. Rien ne
-- change pour lui : il y est seul et voit exactement ce qu'il voyait. C'est ce
-- qui permet à cette migration de ne rien casser.
--
-- Le slug dérive de la partie locale de l'adresse et se termine par les huit
-- premiers caractères de l'identifiant : deux « jean@ » de domaines différents
-- ne peuvent pas se disputer le même slug.
--
-- Un seul énoncé, avec CTE modifiante, pour n'écrire l'expression du slug
-- qu'UNE fois : les organisations ne portent aucune référence vers leur
-- utilisateur, le slug est donc le seul lien disponible entre les deux
-- insertions.
WITH base AS (
    SELECT
        u.id         AS user_id,
        u.full_name  AS name,
        u.created_at AS created_at,
        COALESCE(
            NULLIF(
                btrim(
                    regexp_replace(lower(split_part(u.email::text, '@', 1)), '[^a-z0-9]+', '-', 'g'),
                    '-'
                ),
                ''
            ),
            'espace'
        ) || '-' || substr(u.id::text, 1, 8) AS slug
    FROM users u
),
creees AS (
    INSERT INTO organizations (name, slug, created_at)
    SELECT name, slug, created_at FROM base
    RETURNING id, slug
)
INSERT INTO memberships (organization_id, user_id, role, created_at)
SELECT c.id, b.user_id, 'owner', b.created_at
FROM creees c
-- Égalité explicitement en TEXT : les deux slugs sont déjà en minuscules,
-- autant qu'elle ne dépende pas des règles de coercition entre CITEXT et TEXT.
JOIN base b ON b.slug = c.slug::text;
