-- ============================================================================
--  L'application se supervise elle-même
--
--  ---------------------------------------------------------------------------
--  CE QUI MANQUAIT
--
--  Le module Supervision range les erreurs des applications de ses
--  utilisateurs. Celles de l'application qui l'héberge partaient dans
--  « error_log », c'est-à-dire dans la sortie d'un conteneur que personne ne
--  lit. Une panne de l'API ne laissait aucune trace consultable : le seul
--  signal était un utilisateur qui finissait par écrire au support, sans
--  pouvoir dire autre chose que « ça ne marche pas ».
--
--  ---------------------------------------------------------------------------
--  UN ESPACE « INSTANCE », ET TROIS DÉCISIONS
--
--  1. Les pannes vont dans un ESPACE, pas dans une table à part. L'écran de
--     supervision existe déjà — statuts, courbe, flux temps réel. Le
--     réutiliser vaut mieux qu'un second écran qui divergerait du premier à
--     la première évolution.
--
--  2. On y ENTRE PAR LE RÔLE D'INSTANCE, jamais par invitation.
--     « users.role = 'admin' » désigne l'administration de l'instance depuis
--     le premier jour. L'appartenance à cet espace en est la CONSÉQUENCE,
--     tenue par un déclencheur. Une invitation permettrait à n'importe quel
--     administrateur d'équipe d'ouvrir les pannes de TOUTE l'instance à qui
--     il veut — et une pile d'appels en dit long sur une application.
--
--  3. L'espace naît AU PREMIER BESOIN, par une fonction. Une ligne insérée
--     une fois pour toutes par cette migration disparaîtrait au premier
--     TRUNCATE de la suite de tests, et le signalement échouerait précisément
--     là où on le vérifie.
-- ============================================================================


-- ---------------------------------------------------------------------------
-- 1. La nature d'un espace
-- ---------------------------------------------------------------------------
--
-- Deux valeurs, et une seule ligne « instance » au plus : l'index partiel
-- unique en fait un invariant de la BASE. Deux espaces d'instance créés par
-- deux pannes simultanées ne peuvent pas coexister, quel que soit le code
-- qui s'y essaie.
ALTER TABLE organizations
    ADD COLUMN kind VARCHAR(16) NOT NULL DEFAULT 'team',
    ADD CONSTRAINT organizations_kind_known CHECK (kind IN ('team', 'instance'));

CREATE UNIQUE INDEX organizations_single_instance
    ON organizations (kind)
    WHERE kind = 'instance';

COMMENT ON COLUMN organizations.kind IS
    'team : un espace de travail ; instance : les pannes de l''application elle-même, réservé aux administrateurs de l''instance';


-- ---------------------------------------------------------------------------
-- 2. L'espace de l'instance, créé au premier besoin
-- ---------------------------------------------------------------------------
--
-- Lecture d'abord, écriture ensuite, et la course entre les deux est gagnée
-- par l'index unique : le perdant ne crée rien, relit, et renvoie l'espace du
-- gagnant.
CREATE OR REPLACE FUNCTION instance_organization()
RETURNS UUID AS $fn$
DECLARE
    identifiant UUID;
BEGIN
    SELECT id INTO identifiant FROM organizations WHERE kind = 'instance';

    IF identifiant IS NOT NULL THEN
        RETURN identifiant;
    END IF;

    INSERT INTO organizations (name, slug, kind)
    VALUES ('Instance', 'instance-' || substr(md5(gen_random_uuid()::text), 1, 8), 'instance')
    ON CONFLICT (kind) WHERE kind = 'instance' DO NOTHING
    RETURNING id INTO identifiant;

    IF identifiant IS NULL THEN
        SELECT id INTO identifiant FROM organizations WHERE kind = 'instance';

        RETURN identifiant;
    END IF;

    -- Le déclencheur d'approvisionnement vient de lui donner les cinq
    -- modules. Seule la supervision a un sens ici : des tickets ou des
    -- déploiements « de l'instance » seraient un espace d'équipe déguisé.
    UPDATE organization_modules om
       SET is_enabled = (m.slug = 'supervision')
      FROM modules m
     WHERE m.id = om.module_id
       AND om.organization_id = identifiant;

    -- Les administrateurs déjà en place. Ceux qui le deviendront passent par
    -- le déclencheur de la section 3.
    INSERT INTO memberships (organization_id, user_id, role)
    SELECT identifiant, u.id, 'owner'::membership_role
      FROM users u
     WHERE u.role = 'admin'
    ON CONFLICT DO NOTHING;

    RETURN identifiant;
END;
$fn$ LANGUAGE plpgsql;

COMMENT ON FUNCTION instance_organization() IS
    'Identifiant de l''espace de l''instance, créé s''il n''existe pas encore';


-- ---------------------------------------------------------------------------
-- 3. L'appartenance suit le rôle d'instance
-- ---------------------------------------------------------------------------
--
-- « UPDATE OF role » : le déclencheur ne se réveille pas à chaque connexion
-- (last_login_at) ni à chaque bascule d'espace (active_organization_id).
--
-- Le retrait ne touche que l'espace de l'instance. Un administrateur
-- rétrogradé garde ses espaces d'équipe — c'est son rôle d'INSTANCE qui a
-- changé, pas sa place dans une équipe.
CREATE OR REPLACE FUNCTION sync_instance_membership()
RETURNS TRIGGER AS $fn$
BEGIN
    IF NEW.role = 'admin' AND (TG_OP = 'INSERT' OR OLD.role IS DISTINCT FROM 'admin') THEN
        INSERT INTO memberships (organization_id, user_id, role)
        VALUES (instance_organization(), NEW.id, 'owner')
        ON CONFLICT DO NOTHING;
    ELSIF TG_OP = 'UPDATE' AND OLD.role = 'admin' AND NEW.role IS DISTINCT FROM 'admin' THEN
        DELETE FROM memberships m
         USING organizations o
         WHERE o.id = m.organization_id
           AND o.kind = 'instance'
           AND m.user_id = NEW.id;
    END IF;

    RETURN NULL;
END;
$fn$ LANGUAGE plpgsql;

CREATE TRIGGER users_sync_instance_membership
    AFTER INSERT OR UPDATE OF role ON users
    FOR EACH ROW EXECUTE FUNCTION sync_instance_membership();


-- ---------------------------------------------------------------------------
-- 4. Les administrateurs d'aujourd'hui y trouvent l'espace dès maintenant
-- ---------------------------------------------------------------------------
--
-- Sans cela, l'espace n'apparaîtrait qu'à la première panne — et c'est
-- justement le jour où l'on n'a pas le temps de découvrir où elle est rangée.
DO $reprise$
BEGIN
    IF EXISTS (SELECT 1 FROM users WHERE role = 'admin') THEN
        PERFORM instance_organization();
    END IF;
END;
$reprise$;
