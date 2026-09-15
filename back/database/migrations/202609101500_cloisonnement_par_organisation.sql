-- ============================================================================
--  Le cloisonnement passe du compte à l'organisation
--
--  ---------------------------------------------------------------------------
--  CE QUE « user_id » VOULAIT DIRE
--
--  Sur quatorze tables, la même colonne portait DEUX sens confondus :
--
--     — le CLOISONNEMENT : « qui a le droit de voir cette ligne »
--     — la PATERNITÉ     : « qui a écrit cette ligne »
--
--  Tant qu'un compte égalait un espace de travail, les deux coïncidaient et
--  rien ne l'exigeait. Ils divergent dès qu'on est deux.
--
--  Cette migration les sépare :
--
--     organization_id  →  qui peut voir          (nouveau, NOT NULL)
--     created_by       →  qui a écrit            (l'ancien user_id, renommé)
--
--  ---------------------------------------------------------------------------
--  POURQUOI RENOMMER PLUTÔT QUE GARDER « user_id »
--
--  Parce qu'un « WHERE user_id = :user_id » oublié dans un dépôt PHP ne
--  provoquerait aucune erreur : il continuerait de filtrer, sur la mauvaise
--  colonne, et laisserait fuir les données d'un coéquipier.
--
--  Le renommage transforme cet oubli en erreur SQL immédiate. C'est la seule
--  raison de le faire, et elle suffit.
--
--  ---------------------------------------------------------------------------
--  CE QUI NE BOUGE PAS
--
--     user_settings, user_tokens, refresh_tokens  — préférences, jetons de
--     confirmation et sessions restent PERSONNELS. Le thème n'appartient pas
--     à l'équipe.
--
--  ---------------------------------------------------------------------------
--  UN CHANGEMENT DE COMPORTEMENT À ASSUMER
--
--  « created_by » est NULLABLE et son ON DELETE passe de CASCADE à SET NULL.
--  Supprimer un compte ne supprime donc plus ses tickets : ils appartiennent
--  désormais à l'organisation, et le départ d'un membre ne doit pas emporter
--  le travail de l'équipe. Sa trace de paternité, elle, disparaît bien.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- Table de correspondance, le temps de la migration
-- ---------------------------------------------------------------------------
--
-- Chaque compte n'a qu'une organisation à cet instant — celle que la migration
-- précédente vient de lui créer. Le DISTINCT ON reste néanmoins déterministe
-- si ce n'était pas le cas : c'est la plus ancienne appartenance qui gagne.
CREATE TEMP TABLE reprise_orga AS
SELECT DISTINCT ON (user_id) user_id, organization_id
FROM memberships
ORDER BY user_id, created_at, organization_id;

CREATE UNIQUE INDEX reprise_orga_idx ON reprise_orga (user_id);


-- ===========================================================================
--  1. module_items — le fourre-tout générique des modules
-- ===========================================================================
ALTER TABLE module_items ADD COLUMN organization_id UUID REFERENCES organizations (id) ON DELETE CASCADE;
UPDATE module_items t SET organization_id = r.organization_id FROM reprise_orga r WHERE r.user_id = t.user_id;
ALTER TABLE module_items ALTER COLUMN organization_id SET NOT NULL;

ALTER TABLE module_items RENAME COLUMN user_id TO created_by;
ALTER TABLE module_items ALTER COLUMN created_by DROP NOT NULL;
ALTER TABLE module_items DROP CONSTRAINT module_items_user_id_fkey;
ALTER TABLE module_items ADD CONSTRAINT module_items_created_by_fkey
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL;

-- Les index de liste menaient tous par user_id ; ils mènent maintenant par
-- organization_id. La colonne de tête d'un index composé EST le filtre : la
-- laisser sur created_by rendrait chaque liste incapable de s'en servir.
DROP INDEX module_items_status_idx;
DROP INDEX module_items_listing_idx;
CREATE INDEX module_items_status_idx  ON module_items (organization_id, status) WHERE deleted_at IS NULL;
CREATE INDEX module_items_listing_idx ON module_items (organization_id, module_id, created_at DESC) WHERE deleted_at IS NULL;

COMMENT ON COLUMN module_items.organization_id IS 'Cloisonnement : qui peut voir cette ligne';
COMMENT ON COLUMN module_items.created_by      IS 'Paternité : qui l''a écrite — NULL si le compte a été supprimé';


-- ===========================================================================
--  2. tickets
-- ===========================================================================
ALTER TABLE tickets ADD COLUMN organization_id UUID REFERENCES organizations (id) ON DELETE CASCADE;
UPDATE tickets t SET organization_id = r.organization_id FROM reprise_orga r WHERE r.user_id = t.user_id;
ALTER TABLE tickets ALTER COLUMN organization_id SET NOT NULL;

ALTER TABLE tickets RENAME COLUMN user_id TO created_by;
ALTER TABLE tickets ALTER COLUMN created_by DROP NOT NULL;
ALTER TABLE tickets DROP CONSTRAINT tickets_user_id_fkey;
ALTER TABLE tickets ADD CONSTRAINT tickets_created_by_fkey
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL;

DROP INDEX tickets_board_idx;
DROP INDEX tickets_project_idx;
DROP INDEX tickets_due_idx;
DROP INDEX tickets_number_idx;
-- Une CONTRAINTE, pas un simple index : elle se retire par ALTER TABLE.
ALTER TABLE tickets DROP CONSTRAINT tickets_number_unique;

CREATE INDEX tickets_board_idx   ON tickets (organization_id, status, priority DESC, created_at DESC) WHERE deleted_at IS NULL;
CREATE INDEX tickets_project_idx ON tickets (organization_id, project) WHERE deleted_at IS NULL AND project IS NOT NULL;
CREATE INDEX tickets_due_idx     ON tickets (organization_id, due_date)
    WHERE deleted_at IS NULL AND due_date IS NOT NULL AND status <> ALL (ARRAY['done'::ticket_status, 'canceled'::ticket_status]);
CREATE INDEX tickets_number_idx  ON tickets (organization_id, number DESC) WHERE deleted_at IS NULL;

-- « TICK-42 » devient unique dans l'ORGANISATION. Aucune renumérotation n'est
-- nécessaire : chaque organisation ne contient aujourd'hui qu'un compte, la
-- nouvelle unicité est donc exactement l'ancienne.
ALTER TABLE tickets ADD CONSTRAINT tickets_number_unique UNIQUE (organization_id, number);

COMMENT ON COLUMN tickets.organization_id IS 'Cloisonnement : qui peut voir ce ticket';
COMMENT ON COLUMN tickets.created_by      IS 'Paternité : qui l''a ouvert — NULL si le compte a été supprimé';


-- ===========================================================================
--  3. ticket_counters — la source des numéros
-- ===========================================================================
--
-- Un compteur n'a pas d'auteur : « user_id » n'y était QUE du cloisonnement.
-- Il disparaît entièrement, et la clé primaire devient l'organisation.
--
-- Le compteur est désormais partagé : deux coéquipiers qui ouvrent un ticket
-- en même temps obtiennent deux numéros différents, parce que la ligne est
-- verrouillée le temps de l'incrément.
ALTER TABLE ticket_counters ADD COLUMN organization_id UUID REFERENCES organizations (id) ON DELETE CASCADE;
UPDATE ticket_counters c SET organization_id = r.organization_id FROM reprise_orga r WHERE r.user_id = c.user_id;

ALTER TABLE ticket_counters DROP CONSTRAINT ticket_counters_pkey;
ALTER TABLE ticket_counters DROP COLUMN user_id;
ALTER TABLE ticket_counters ALTER COLUMN organization_id SET NOT NULL;
ALTER TABLE ticket_counters ADD PRIMARY KEY (organization_id);

COMMENT ON TABLE ticket_counters IS 'Dernier numéro de ticket attribué, par organisation';

-- Le déclencheur qui pose « number » lisait NEW.user_id et le compteur du
-- compte. Il suit, à l'identique : c'est toujours l'UPDATE … RETURNING qui
-- verrouille la ligne et sérialise les créations concurrentes — simplement,
-- ce qui se sérialise est désormais l'ÉQUIPE, et non plus une personne.
CREATE OR REPLACE FUNCTION assign_ticket_number()
RETURNS TRIGGER AS $fn$
BEGIN
    -- Numéro imposé (reprise de données, tests) : on le respecte.
    IF NEW.number IS NOT NULL THEN
        RETURN NEW;
    END IF;

    INSERT INTO ticket_counters (organization_id, last_number)
         VALUES (NEW.organization_id, 1)
    ON CONFLICT (organization_id) DO UPDATE
            SET last_number = ticket_counters.last_number + 1
      RETURNING last_number INTO NEW.number;

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;


-- ===========================================================================
--  4. backend_tables
-- ===========================================================================
ALTER TABLE backend_tables ADD COLUMN organization_id UUID REFERENCES organizations (id) ON DELETE CASCADE;
UPDATE backend_tables t SET organization_id = r.organization_id FROM reprise_orga r WHERE r.user_id = t.user_id;
ALTER TABLE backend_tables ALTER COLUMN organization_id SET NOT NULL;

ALTER TABLE backend_tables RENAME COLUMN user_id TO created_by;
ALTER TABLE backend_tables ALTER COLUMN created_by DROP NOT NULL;
ALTER TABLE backend_tables DROP CONSTRAINT backend_tables_user_id_fkey;
ALTER TABLE backend_tables ADD CONSTRAINT backend_tables_created_by_fkey
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL;

DROP INDEX backend_tables_listing_idx;
DROP INDEX backend_tables_name_unique;
CREATE INDEX backend_tables_listing_idx ON backend_tables (organization_id, created_at DESC) WHERE deleted_at IS NULL;
-- Deux membres ne peuvent pas créer deux tables « clients » dans le même
-- espace : le nom est unique dans l'organisation, pas par personne.
CREATE UNIQUE INDEX backend_tables_name_unique ON backend_tables (organization_id, name) WHERE deleted_at IS NULL;

COMMENT ON COLUMN backend_tables.organization_id IS 'Cloisonnement : qui peut voir cette table';
COMMENT ON COLUMN backend_tables.created_by      IS 'Paternité : qui l''a déclarée — NULL si le compte a été supprimé';


-- ===========================================================================
--  5. backend_api_keys
-- ===========================================================================
--
-- Une clé d'API appartient à l'organisation : elle doit survivre au départ de
-- celui qui l'a émise, sans quoi une intégration en production s'arrêterait
-- le jour d'un désabonnement. Sa paternité, elle, reste précieuse — c'est ce
-- qu'on regarde quand on se demande à quoi sert une clé.
ALTER TABLE backend_api_keys ADD COLUMN organization_id UUID REFERENCES organizations (id) ON DELETE CASCADE;
UPDATE backend_api_keys k SET organization_id = r.organization_id FROM reprise_orga r WHERE r.user_id = k.user_id;
ALTER TABLE backend_api_keys ALTER COLUMN organization_id SET NOT NULL;

ALTER TABLE backend_api_keys RENAME COLUMN user_id TO created_by;
ALTER TABLE backend_api_keys ALTER COLUMN created_by DROP NOT NULL;
ALTER TABLE backend_api_keys DROP CONSTRAINT backend_api_keys_user_id_fkey;
ALTER TABLE backend_api_keys ADD CONSTRAINT backend_api_keys_created_by_fkey
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL;

DROP INDEX backend_api_keys_listing_idx;
CREATE INDEX backend_api_keys_listing_idx ON backend_api_keys (organization_id, created_at DESC);

COMMENT ON COLUMN backend_api_keys.organization_id IS 'Cloisonnement : qui peut voir cette clé';
COMMENT ON COLUMN backend_api_keys.created_by      IS 'Paternité : qui l''a émise — NULL si le compte a été supprimé';


-- ===========================================================================
--  6. deployments
-- ===========================================================================
ALTER TABLE deployments ADD COLUMN organization_id UUID REFERENCES organizations (id) ON DELETE CASCADE;
UPDATE deployments d SET organization_id = r.organization_id FROM reprise_orga r WHERE r.user_id = d.user_id;
ALTER TABLE deployments ALTER COLUMN organization_id SET NOT NULL;

ALTER TABLE deployments RENAME COLUMN user_id TO created_by;
ALTER TABLE deployments ALTER COLUMN created_by DROP NOT NULL;
ALTER TABLE deployments DROP CONSTRAINT deployments_user_id_fkey;
ALTER TABLE deployments ADD CONSTRAINT deployments_created_by_fkey
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL;

DROP INDEX deployments_env_idx;
DROP INDEX deployments_listing_idx;
CREATE INDEX deployments_env_idx     ON deployments (organization_id, environment, created_at DESC) WHERE deleted_at IS NULL;
CREATE INDEX deployments_listing_idx ON deployments (organization_id, created_at DESC) WHERE deleted_at IS NULL;

COMMENT ON COLUMN deployments.organization_id IS 'Cloisonnement : qui peut voir ce déploiement';
COMMENT ON COLUMN deployments.created_by      IS 'Paternité : qui l''a déclenché — NULL si le compte a été supprimé';


-- ===========================================================================
--  7. error_groups — regroupement d'erreurs par empreinte
-- ===========================================================================
--
-- Une erreur regroupée n'a PAS d'auteur : elle naît de la première occurrence
-- reçue. « user_id » n'y était que du cloisonnement, il disparaît.
--
-- L'empreinte devient unique par organisation : deux membres qui rencontrent
-- la même erreur alimentent le MÊME groupe, ce qui est tout l'intérêt d'une
-- supervision d'équipe.
ALTER TABLE error_groups ADD COLUMN organization_id UUID REFERENCES organizations (id) ON DELETE CASCADE;
UPDATE error_groups g SET organization_id = r.organization_id FROM reprise_orga r WHERE r.user_id = g.user_id;
ALTER TABLE error_groups ALTER COLUMN organization_id SET NOT NULL;

DROP INDEX error_groups_listing_idx;
ALTER TABLE error_groups DROP CONSTRAINT error_groups_fingerprint_unique;
ALTER TABLE error_groups DROP COLUMN user_id;

CREATE INDEX error_groups_listing_idx ON error_groups (organization_id, status, last_seen_at DESC) WHERE deleted_at IS NULL;
ALTER TABLE error_groups ADD CONSTRAINT error_groups_fingerprint_unique UNIQUE (organization_id, fingerprint);

COMMENT ON COLUMN error_groups.organization_id IS 'Cloisonnement : qui peut voir ce groupe d''erreurs';


-- ===========================================================================
--  8. error_events — les occurrences
-- ===========================================================================
--
-- La table portait une COPIE de user_id pour que le comptage sur 24 h n'ait
-- pas à joindre les groupes. La copie demeure, elle change simplement de
-- sujet : c'est l'organisation qu'elle recopie désormais.
ALTER TABLE error_events ADD COLUMN organization_id UUID REFERENCES organizations (id) ON DELETE CASCADE;
UPDATE error_events e SET organization_id = r.organization_id FROM reprise_orga r WHERE r.user_id = e.user_id;
ALTER TABLE error_events ALTER COLUMN organization_id SET NOT NULL;

DROP INDEX error_events_user_idx;
ALTER TABLE error_events DROP COLUMN user_id;
CREATE INDEX error_events_org_idx ON error_events (organization_id, occurred_at DESC);

COMMENT ON COLUMN error_events.organization_id IS 'Copie du cloisonnement du groupe, pour compter sans jointure';


-- ===========================================================================
--  9. design_files
-- ===========================================================================
ALTER TABLE design_files ADD COLUMN organization_id UUID REFERENCES organizations (id) ON DELETE CASCADE;
UPDATE design_files f SET organization_id = r.organization_id FROM reprise_orga r WHERE r.user_id = f.user_id;
ALTER TABLE design_files ALTER COLUMN organization_id SET NOT NULL;

ALTER TABLE design_files RENAME COLUMN user_id TO created_by;
ALTER TABLE design_files ALTER COLUMN created_by DROP NOT NULL;
ALTER TABLE design_files DROP CONSTRAINT design_files_user_id_fkey;
ALTER TABLE design_files ADD CONSTRAINT design_files_created_by_fkey
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL;

DROP INDEX design_files_listing_idx;
CREATE INDEX design_files_listing_idx ON design_files (organization_id, updated_at DESC) WHERE deleted_at IS NULL;

COMMENT ON COLUMN design_files.organization_id IS 'Cloisonnement : qui peut voir ce fichier';
COMMENT ON COLUMN design_files.created_by      IS 'Paternité : qui l''a créé — NULL si le compte a été supprimé';


-- ===========================================================================
--  10. design_versions — l'historique d'un fichier
-- ===========================================================================
--
-- Seule table à recevoir LES DEUX colonnes, et c'est justifié : la copie du
-- cloisonnement sert au comptage global de l'écran Design, tandis que la
-- paternité est affichée dans le panneau d'historique — « qui a publié cette
-- version » est précisément ce qu'on y cherche à plusieurs.
ALTER TABLE design_versions ADD COLUMN organization_id UUID REFERENCES organizations (id) ON DELETE CASCADE;
UPDATE design_versions v SET organization_id = r.organization_id FROM reprise_orga r WHERE r.user_id = v.user_id;
ALTER TABLE design_versions ALTER COLUMN organization_id SET NOT NULL;

ALTER TABLE design_versions RENAME COLUMN user_id TO created_by;
ALTER TABLE design_versions ALTER COLUMN created_by DROP NOT NULL;
ALTER TABLE design_versions DROP CONSTRAINT design_versions_user_id_fkey;
ALTER TABLE design_versions ADD CONSTRAINT design_versions_created_by_fkey
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL;

-- La colonne de filtre n'avait aucun index : le comptage global parcourait la
-- table entière. Puisqu'elle change de sujet, autant lui en donner un.
CREATE INDEX design_versions_org_idx ON design_versions (organization_id);

COMMENT ON COLUMN design_versions.organization_id IS 'Copie du cloisonnement du fichier, pour compter sans jointure';
COMMENT ON COLUMN design_versions.created_by      IS 'Paternité : qui a publié cette version — NULL si le compte a été supprimé';


-- ===========================================================================
--  11. user_modules → organization_modules
-- ===========================================================================
--
-- Activer un module est une décision d'ÉQUIPE : tout le monde voit la même
-- barre latérale. La table change donc de nom en même temps que de clé — un
-- « user_modules » cloisonné par organisation serait un mensonge.
ALTER TABLE user_modules ADD COLUMN organization_id UUID REFERENCES organizations (id) ON DELETE CASCADE;
UPDATE user_modules um SET organization_id = r.organization_id FROM reprise_orga r WHERE r.user_id = um.user_id;
ALTER TABLE user_modules ALTER COLUMN organization_id SET NOT NULL;

DROP INDEX user_modules_enabled_idx;
ALTER TABLE user_modules DROP CONSTRAINT user_modules_pkey;
ALTER TABLE user_modules DROP COLUMN user_id;

ALTER TABLE user_modules RENAME TO organization_modules;
ALTER TABLE organization_modules ADD CONSTRAINT organization_modules_pkey PRIMARY KEY (organization_id, module_id);
ALTER TRIGGER user_modules_set_updated_at ON organization_modules RENAME TO organization_modules_set_updated_at;

CREATE INDEX organization_modules_enabled_idx ON organization_modules (organization_id) WHERE is_enabled;

COMMENT ON TABLE organization_modules IS 'Modules activés pour une organisation, et leurs réglages';


-- ===========================================================================
--  12. Le provisionnement suit le déplacement
-- ===========================================================================
--
-- « provision_new_user() » attribuait les modules actifs au COMPTE, dans une
-- table qui vient de changer de nom et de clé. Sans cette section, la
-- migration laisserait une inscription impossible derrière elle — et la
-- promesse faite en tête de ces deux fichiers, qu'ils forment chacun un point
-- de contrôle vérifiable, serait fausse.
--
-- Le provisionnement se scinde comme le reste :
--
--   à la création d'un COMPTE        → ses préférences
--   à la création d'une ORGANISATION → ses modules
CREATE OR REPLACE FUNCTION provision_new_user()
RETURNS TRIGGER AS $fn$
BEGIN
    INSERT INTO user_settings (user_id) VALUES (NEW.id);

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION provision_new_organization()
RETURNS TRIGGER AS $fn$
BEGIN
    INSERT INTO organization_modules (organization_id, module_id)
    SELECT NEW.id, m.id FROM modules m WHERE m.is_active;

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;

-- Posé APRÈS la reprise de la migration précédente, à dessein : les
-- organisations créées par celle-ci tiennent leurs modules des lignes
-- « user_modules » reprises en section 11, et un déclencheur qui aurait
-- existé plus tôt les aurait insérés en double.
CREATE TRIGGER organizations_provision_modules
    AFTER INSERT ON organizations
    FOR EACH ROW EXECUTE FUNCTION provision_new_organization();


-- ===========================================================================
--  13. Les schémas physiques du module Backend suivent aussi
-- ===========================================================================
--
-- SchemaBuilder ne décrit pas des tables : il en CRÉE, dans un schéma
-- PostgreSQL par compte, nommé « u_<identifiant sans tirets> ». Ce sont de
-- vraies données, écrites par de vraies intégrations.
--
-- Les renommer est la seule option honnête. Les laisser en place aurait
-- produit un écran qui liste des tables dont les lignes ont disparu — le
-- schéma serait toujours là, sous un nom que plus aucun code ne calcule.
--
-- Un schéma sans appartenance correspondante est laissé tel quel : la boucle
-- ne renomme que ce qu'elle sait rattacher.
DO $migration$
DECLARE
    ligne RECORD;
BEGIN
    FOR ligne IN
        SELECT n.nspname                                              AS ancien,
               'o_' || replace(m.organization_id::text, '-', '')      AS nouveau
          FROM pg_namespace n
          JOIN memberships m
            ON n.nspname = 'u_' || replace(m.user_id::text, '-', '')
    LOOP
        EXECUTE format('ALTER SCHEMA %I RENAME TO %I', ligne.ancien, ligne.nouveau);
    END LOOP;
END
$migration$;


-- ---------------------------------------------------------------------------
DROP TABLE reprise_orga;
