-- ============================================================================
--  Documentation : ce que l'équipe sait, rangé à côté de ce qu'elle fait
--
--  ---------------------------------------------------------------------------
--  LE SAVOIR QUI VIVAIT AILLEURS
--
--  « Comment restaurer une sauvegarde », « pourquoi les e-mails passent par la
--  file », « que faire quand le paiement tombe » : ces pages vivaient dans un
--  autre outil, que personne n'ouvrait au moment où il fallait. Une panne se
--  voit dans Disponibilité ; la procédure pour la réparer doit être à un clic,
--  pas dans un onglet qu'on a oublié d'ouvrir.
--
--  ---------------------------------------------------------------------------
--  UNE PAGE EST DU TEXTE, JAMAIS DU HTML
--
--  Le corps est stocké tel qu'il a été écrit. Il n'est jamais transformé en
--  HTML — ni ici, ni dans l'API, ni dans le navigateur : le client le découpe
--  en titres, paragraphes, listes et blocs de code, et les affiche comme du
--  TEXTE (cf. front/src/utils/richText.js). « <script> » écrit dans une page
--  s'affiche « <script> ». Aucune liste noire à tenir à jour, parce qu'aucun
--  balisage n'est jamais interprété.
--
--  ---------------------------------------------------------------------------
--  UN ARBRE PEU PROFOND
--
--  Une page peut avoir des sous-pages. La profondeur n'est pas bornée en SQL —
--  une contrainte récursive coûterait plus qu'elle ne protège —, mais une page
--  ne peut pas devenir sa propre ancêtre : le contrôleur le refuse, et la
--  suppression d'une page qui a encore des sous-pages aussi.
-- ============================================================================


CREATE TABLE doc_pages (
    id              UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id UUID          NOT NULL REFERENCES organizations (id) ON DELETE CASCADE,

    -- ON DELETE RESTRICT : une suppression DÉFINITIVE d'une page parente,
    -- même en psql, ne laisse pas d'orphelines derrière elle.
    parent_id       UUID          REFERENCES doc_pages (id) ON DELETE RESTRICT,

    title           VARCHAR(160)  NOT NULL,
    body            TEXT          NOT NULL DEFAULT '',
    position        INTEGER       NOT NULL DEFAULT 0,

    created_by      UUID          REFERENCES users (id) ON DELETE SET NULL,
    updated_by      UUID          REFERENCES users (id) ON DELETE SET NULL,

    version         INTEGER       NOT NULL DEFAULT 1,
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    deleted_at      TIMESTAMPTZ,

    CONSTRAINT doc_pages_title_len  CHECK (char_length(btrim(title)) >= 1),
    -- Deux cent mille caractères : un long manuel tient, un fichier de journaux
    -- collé par erreur ne gonfle pas chaque sauvegarde de la base.
    CONSTRAINT doc_pages_body_len   CHECK (char_length(body) <= 200000),
    CONSTRAINT doc_pages_not_parent CHECK (parent_id IS NULL OR parent_id <> id)
);

COMMENT ON TABLE doc_pages IS 'Pages de documentation d''un espace ; le corps est du texte, jamais du HTML';
COMMENT ON COLUMN doc_pages.version IS 'Jeton de concurrence, posé par déclencheur — jamais fourni par le client';

CREATE INDEX doc_pages_tree_idx
    ON doc_pages (organization_id, parent_id, position, title)
 WHERE deleted_at IS NULL;

CREATE TRIGGER doc_pages_set_updated_at
    BEFORE UPDATE ON doc_pages
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TRIGGER doc_pages_bump_version
    BEFORE UPDATE ON doc_pages
    FOR EACH ROW EXECUTE FUNCTION bump_version();


-- ---------------------------------------------------------------------------
-- Le module, pour chaque espace
-- ---------------------------------------------------------------------------
INSERT INTO modules (slug, name, description, icon, position)
VALUES (
    'documentation',
    'Documentation',
    'Les procédures, les décisions et ce que l''équipe sait, rangés à côté des tickets et des pannes qu''ils expliquent.',
    'book',
    60
)
ON CONFLICT (slug) DO NOTHING;

INSERT INTO organization_modules (organization_id, module_id)
SELECT o.id, m.id
  FROM organizations o
 CROSS JOIN modules m
 WHERE m.slug = 'documentation'
ON CONFLICT DO NOTHING;
