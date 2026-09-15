-- ============================================================================
--  Module « Tickets » — suivi clavier-first
--
--  Première table MÉTIER de l'application. Les cinq modules partageaient
--  jusqu'ici la table générique module_items (titre, statut, échéance,
--  charge utile JSONB) : cinq étiquettes sur la même liste. Un suivi de
--  tickets a ses propres notions — un numéro, une priorité ordonnée, un
--  cycle de vie — qu'un JSONB ne sait ni contraindre ni indexer.
--
--  Les invariants sont tenus par la BASE et non par l'application :
--  numérotation, date de clôture et updated_at sont posés par des triggers.
--  Une insertion manuelle en psql produit donc une ligne aussi correcte
--  qu'un passage par l'API.
-- ============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- Types énumérés
--
-- L'ORDRE DE DÉCLARATION EST SIGNIFIANT : PostgreSQL ordonne un type énuméré
-- selon sa déclaration. « ORDER BY priority DESC » remonte donc urgent en
-- tête sans table de correspondance ni CASE, et l'ordre reste juste si une
-- valeur est ajoutée plus tard au bon endroit.
-- ---------------------------------------------------------------------------
CREATE TYPE ticket_status   AS ENUM ('backlog', 'todo', 'in_progress', 'done', 'canceled');
CREATE TYPE ticket_priority AS ENUM ('none', 'low', 'medium', 'high', 'urgent');

COMMENT ON TYPE ticket_status   IS 'Cycle de vie : backlog -> todo -> in_progress -> done | canceled';
COMMENT ON TYPE ticket_priority IS 'Ordonné du plus faible au plus fort — l''ordre de déclaration porte le tri';


-- ===========================================================================
--  ticket_counters — numérotation séquentielle par compte
--
--  Un suivi clavier-first se désigne par des numéros (« ouvre 128 »), pas par
--  des UUID. Chaque compte a donc sa propre suite, repartant de 1.
--
--  Une séquence PostgreSQL ne convient pas : elle est globale, les numéros
--  d'un compte sauteraient au gré des créations des autres. « MAX(number)+1 »
--  ne convient pas davantage : deux créations simultanées liraient le même
--  maximum. Ce compteur est incrémenté par UPDATE … RETURNING, qui verrouille
--  la ligne du compte le temps de la transaction et sérialise donc les
--  créations concurrentes du MÊME utilisateur, sans jamais gêner les autres.
-- ===========================================================================
CREATE TABLE ticket_counters (
    user_id     UUID    PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    last_number INTEGER NOT NULL DEFAULT 0
);

COMMENT ON TABLE ticket_counters IS 'Dernier numéro de ticket attribué, par compte';


-- ===========================================================================
--  tickets
-- ===========================================================================
CREATE TABLE tickets (
    id           UUID            PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id      UUID            NOT NULL REFERENCES users(id) ON DELETE CASCADE,

    -- Posé par le trigger tickets_assign_number : jamais fourni par l'API.
    number       INTEGER         NOT NULL,

    title        VARCHAR(200)    NOT NULL,
    description  TEXT,
    status       ticket_status   NOT NULL DEFAULT 'todo',
    priority     ticket_priority NOT NULL DEFAULT 'none',

    -- Projet en texte libre plutôt qu'en table dédiée : à ce stade, une
    -- table de projets imposerait un écran de gestion pour un champ que
    -- l'on saisit une fois et que l'autocomplétion propose ensuite.
    project      VARCHAR(60),

    -- Tableau natif plutôt que JSONB : les étiquettes sont une liste de
    -- chaînes, pas un document. Un index GIN sur text[] répond directement
    -- à « labels @> ARRAY['bug'] ».
    labels       TEXT[]          NOT NULL DEFAULT '{}',

    due_date     DATE,

    -- Dérivé du statut par trigger, jamais écrit par l'application : deux
    -- sources pour un même fait finissent toujours par diverger.
    completed_at TIMESTAMPTZ,

    created_at   TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
    deleted_at   TIMESTAMPTZ,

    -- Le numéro est définitif : un ticket supprimé garde le sien, et aucun
    -- nouveau ticket ne le réutilise. Une référence écrite ailleurs (commit,
    -- conversation) ne doit jamais désigner un autre ticket qu'à l'origine.
    CONSTRAINT tickets_number_unique  UNIQUE (user_id, number),
    CONSTRAINT tickets_number_positive CHECK (number > 0),
    CONSTRAINT tickets_title_len      CHECK (char_length(btrim(title)) >= 1),
    CONSTRAINT tickets_labels_bounded CHECK (cardinality(labels) <= 8)
);

COMMENT ON TABLE  tickets              IS 'Tickets du module « Tickets » — modèle métier propre';
COMMENT ON COLUMN tickets.number       IS 'Numéro séquentiel par compte, attribué par trigger';
COMMENT ON COLUMN tickets.completed_at IS 'Dérivé du statut par trigger — ne pas écrire depuis l''application';
COMMENT ON COLUMN tickets.deleted_at   IS 'Suppression logique : NULL = visible';


-- ---------------------------------------------------------------------------
-- Index
-- ---------------------------------------------------------------------------
-- Listing principal : « les tickets de l'utilisateur X, groupés par statut,
-- les plus prioritaires d'abord ». Partiel sur deleted_at : la corbeille
-- n'alourdit pas l'index.
CREATE INDEX tickets_board_idx
    ON tickets (user_id, status, priority DESC, created_at DESC)
    WHERE deleted_at IS NULL;

-- Accès direct par numéro (« ouvre 128 »), le geste le plus fréquent du
-- clavier après la création.
CREATE INDEX tickets_number_idx
    ON tickets (user_id, number DESC)
    WHERE deleted_at IS NULL;

CREATE INDEX tickets_project_idx
    ON tickets (user_id, project)
    WHERE deleted_at IS NULL AND project IS NOT NULL;

CREATE INDEX tickets_labels_gin_idx ON tickets USING GIN (labels);

-- Échéances dépassées : l'index ne porte que sur les tickets encore ouverts,
-- seuls concernés par la notion de retard.
CREATE INDEX tickets_due_idx
    ON tickets (user_id, due_date)
    WHERE deleted_at IS NULL AND due_date IS NOT NULL AND status NOT IN ('done', 'canceled');


-- ---------------------------------------------------------------------------
-- Trigger : attribution du numéro
--
-- BEFORE INSERT, donc avant la vérification du NOT NULL sur « number ».
-- L'UPSERT crée la ligne de compteur au premier ticket : rien à provisionner
-- à l'inscription, et les comptes déjà existants sont couverts sans reprise.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION assign_ticket_number()
RETURNS TRIGGER AS $fn$
BEGIN
    -- Numéro imposé (reprise de données, tests) : on le respecte.
    IF NEW.number IS NOT NULL THEN
        RETURN NEW;
    END IF;

    INSERT INTO ticket_counters (user_id, last_number)
         VALUES (NEW.user_id, 1)
    ON CONFLICT (user_id) DO UPDATE
            SET last_number = ticket_counters.last_number + 1
      RETURNING last_number INTO NEW.number;

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;

CREATE TRIGGER tickets_assign_number
    BEFORE INSERT ON tickets
    FOR EACH ROW EXECUTE FUNCTION assign_ticket_number();


-- ---------------------------------------------------------------------------
-- Trigger : date de clôture alignée sur le statut
--
-- Rouvrir un ticket efface sa date de clôture. Sans cela, un ticket rouvert
-- puis reclos conserverait la date du PREMIER passage en « terminé », et
-- toute mesure de délai construite dessus serait fausse.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION sync_ticket_completed_at()
RETURNS TRIGGER AS $fn$
BEGIN
    IF NEW.status IN ('done', 'canceled') THEN
        -- Conservée si le ticket était DÉJÀ clos : un passage de « done » à
        -- « canceled » ne redate pas la clôture.
        IF NEW.completed_at IS NULL THEN
            NEW.completed_at = NOW();
        END IF;
    ELSE
        NEW.completed_at = NULL;
    END IF;

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;

CREATE TRIGGER tickets_sync_completed_at
    BEFORE INSERT OR UPDATE OF status ON tickets
    FOR EACH ROW EXECUTE FUNCTION sync_ticket_completed_at();

CREATE TRIGGER tickets_set_updated_at
    BEFORE UPDATE ON tickets
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

COMMIT;
