-- ============================================================================
--  Disponibilité : des sondes qui appellent la production
--
--  ---------------------------------------------------------------------------
--  LE MODULE QUI MANQUAIT ENTRE DÉPLOIEMENT ET SUPERVISION
--
--  Déploiement dit ce qui est parti en production. Supervision dit ce qui y a
--  cassé — mais seulement quand l'application est encore assez vivante pour
--  l'envoyer. Une page qui ne répond plus du tout n'envoie aucune erreur : elle
--  se tait, et le silence ressemblait à la santé.
--
--  Une sonde appelle une adresse à intervalle régulier et consigne ce qu'elle
--  obtient : répondu, répondu lentement, ou pas répondu.
--
--  ---------------------------------------------------------------------------
--  QUATRE TABLES, QUATRE DURÉES DE VIE
--
--    probes           ce qu'on surveille, tel que l'équipe l'a réglé
--    probe_states     où en est chaque sonde, tel que le worker le constate
--    probe_checks     chaque appel, conservé 30 jours (tâche « purge-sondes »)
--    probe_incidents  les pannes, du premier échec au retour ; elles restent,
--                     parce que « combien de temps sommes-nous tombés ce
--                     trimestre » se demande longtemps après
--
--  ---------------------------------------------------------------------------
--  POURQUOI L'ÉTAT N'EST PAS SUR LA SONDE
--
--  Une sonde porte un jeton de version, relevé par déclencheur à chaque
--  modification : c'est lui qui arbitre deux personnes qui la règlent en même
--  temps. Si le worker écrivait l'état du dernier appel sur la même ligne, la
--  version changerait chaque minute — et quiconque modifie une sonde se verrait
--  opposer un conflit avec... le worker. D'où deux tables : l'une versionnée,
--  que seules les personnes modifient ; l'autre non, que seul le worker écrit.
--
--  ---------------------------------------------------------------------------
--  CE QUE LA BASE REFUSE D'ELLE-MÊME
--
--  Méthode, intervalle, délai et seuil sont bornés par des contraintes, pas
--  seulement par le contrôleur : une sonde toutes les secondes, écrite à la
--  main, ferait de Relais l'auteur d'une saturation contre le site qu'elle
--  surveille. L'ADRESSE ne se vérifie pas en SQL : la résolution DNS appartient
--  au code (cf. App\Services\UrlGuard), et elle est refaite à CHAQUE appel.
-- ============================================================================


CREATE TYPE probe_outcome AS ENUM ('up', 'slow', 'down');

COMMENT ON TYPE probe_outcome IS 'Résultat d''un appel : répondu, répondu au-delà du seuil, pas répondu ou en erreur';


-- ---------------------------------------------------------------------------
-- 1. Les sondes, telles que l'équipe les règle
-- ---------------------------------------------------------------------------
CREATE TABLE probes (
    id               UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id  UUID          NOT NULL REFERENCES organizations (id) ON DELETE CASCADE,
    created_by       UUID          REFERENCES users (id) ON DELETE SET NULL,

    name             VARCHAR(80)   NOT NULL,
    url              VARCHAR(400)  NOT NULL,
    method           VARCHAR(4)    NOT NULL DEFAULT 'GET',

    -- Des paliers, pas une valeur libre : « toutes les 47 secondes » n'aide
    -- personne et empêcherait de répartir la charge du worker.
    interval_seconds INTEGER       NOT NULL DEFAULT 300,
    timeout_ms       INTEGER       NOT NULL DEFAULT 5000,
    slow_ms          INTEGER       NOT NULL DEFAULT 1000,
    is_paused        BOOLEAN       NOT NULL DEFAULT FALSE,

    version          INTEGER       NOT NULL DEFAULT 1,
    created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    deleted_at       TIMESTAMPTZ,

    CONSTRAINT probes_name_len   CHECK (char_length(btrim(name)) >= 1),
    CONSTRAINT probes_method     CHECK (method IN ('GET', 'HEAD')),
    CONSTRAINT probes_interval   CHECK (interval_seconds IN (60, 300, 900, 3600)),
    CONSTRAINT probes_timeout    CHECK (timeout_ms BETWEEN 1000 AND 10000),
    CONSTRAINT probes_slow       CHECK (slow_ms BETWEEN 100 AND 10000 AND slow_ms < timeout_ms),
    CONSTRAINT probes_url_scheme CHECK (url ~* '^https?://')
);

COMMENT ON TABLE probes IS 'Adresses appelées à intervalle régulier, telles que l''équipe les règle';
COMMENT ON COLUMN probes.version IS 'Jeton de concurrence, posé par déclencheur — jamais fourni par le client';

CREATE INDEX probes_organization_idx
    ON probes (organization_id, name)
 WHERE deleted_at IS NULL;

CREATE TRIGGER probes_set_updated_at
    BEFORE UPDATE ON probes
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TRIGGER probes_bump_version
    BEFORE UPDATE ON probes
    FOR EACH ROW EXECUTE FUNCTION bump_version();


-- ---------------------------------------------------------------------------
-- 2. Où en est chaque sonde, tel que le worker le constate
-- ---------------------------------------------------------------------------
CREATE TABLE probe_states (
    probe_id         UUID          PRIMARY KEY REFERENCES probes (id) ON DELETE CASCADE,

    -- Quand la prochaine vérification est due. Le worker prend les sondes
    -- échues et REPORTE cette date dans la même instruction : deux workers ne
    -- sonderont pas deux fois la même adresse.
    next_check_at    TIMESTAMPTZ   NOT NULL DEFAULT NOW(),

    last_outcome     probe_outcome,
    last_checked_at  TIMESTAMPTZ,
    last_response_ms INTEGER,
    last_http_status SMALLINT,
    last_error       VARCHAR(200)
);

COMMENT ON TABLE probe_states IS 'Dernier appel et prochaine échéance d''une sonde ; écrit par le worker seul';

CREATE INDEX probe_states_due_idx ON probe_states (next_check_at);

-- Toute sonde a son état dès sa création, quel que soit le chemin qui la crée :
-- le worker n'a jamais à se demander si la ligne existe.
CREATE OR REPLACE FUNCTION provision_probe_state()
RETURNS TRIGGER AS $fn$
BEGIN
    INSERT INTO probe_states (probe_id) VALUES (NEW.id);

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;

CREATE TRIGGER probes_provision_state
    AFTER INSERT ON probes
    FOR EACH ROW EXECUTE FUNCTION provision_probe_state();


-- ---------------------------------------------------------------------------
-- 3. Chaque appel
-- ---------------------------------------------------------------------------
CREATE TABLE probe_checks (
    -- BIGSERIAL : une sonde par minute, c'est un demi-million de lignes par an.
    id              BIGSERIAL     PRIMARY KEY,
    probe_id        UUID          NOT NULL REFERENCES probes (id) ON DELETE CASCADE,
    organization_id UUID          NOT NULL REFERENCES organizations (id) ON DELETE CASCADE,
    checked_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    outcome         probe_outcome NOT NULL,
    http_status     SMALLINT,
    response_ms     INTEGER,
    error           VARCHAR(200)
);

COMMENT ON TABLE probe_checks IS 'Un appel de sonde ; conservé 30 jours';

CREATE INDEX probe_checks_probe_idx ON probe_checks (probe_id, checked_at DESC);
CREATE INDEX probe_checks_purge_idx ON probe_checks (checked_at);


-- ---------------------------------------------------------------------------
-- 4. Les pannes
-- ---------------------------------------------------------------------------
CREATE TABLE probe_incidents (
    id              UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    probe_id        UUID          NOT NULL REFERENCES probes (id) ON DELETE CASCADE,
    organization_id UUID          NOT NULL REFERENCES organizations (id) ON DELETE CASCADE,
    started_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    ended_at        TIMESTAMPTZ,
    cause           VARCHAR(200),

    CONSTRAINT probe_incidents_order CHECK (ended_at IS NULL OR ended_at >= started_at)
);

COMMENT ON TABLE probe_incidents IS 'Une panne de sonde, du premier échec au premier succès';

-- Une seule panne OUVERTE par sonde : deux échecs de suite prolongent la même
-- panne, ils n'en ouvrent pas une seconde. Tenu par la base, parce que deux
-- workers peuvent constater le même échec au même instant.
CREATE UNIQUE INDEX probe_incidents_one_open
    ON probe_incidents (probe_id)
 WHERE ended_at IS NULL;

CREATE INDEX probe_incidents_organization_idx
    ON probe_incidents (organization_id, started_at DESC);


-- ---------------------------------------------------------------------------
-- 5. Le module, pour chaque espace
-- ---------------------------------------------------------------------------
--
-- Entre Supervision (40) et Design (50) : ce qu'on surveille en production se
-- range à côté de ce qui y casse.
INSERT INTO modules (slug, name, description, icon, position)
VALUES (
    'disponibilite',
    'Disponibilité',
    'Des sondes appellent vos adresses à intervalle régulier : une page qui ne répond plus se voit, même quand elle ne peut plus rien signaler elle-même.',
    'signal',
    45
)
ON CONFLICT (slug) DO NOTHING;

-- Les espaces créés APRÈS cette migration reçoivent le module par le
-- déclencheur « organizations_provision_modules ». Ceux qui existent déjà, non :
-- il faut les rattraper ici.
INSERT INTO organization_modules (organization_id, module_id)
SELECT o.id, m.id
  FROM organizations o
 CROSS JOIN modules m
 WHERE m.slug = 'disponibilite'
ON CONFLICT DO NOTHING;


-- ---------------------------------------------------------------------------
-- 6. Ce que le worker fait tourner
-- ---------------------------------------------------------------------------
--
-- « sondes » passe chaque minute et appelle ce qui est échu, par lots : une
-- sonde réglée à 60 s est appelée à la minute près, jamais plus souvent.
INSERT INTO scheduled_tasks (name, type, payload, interval_seconds)
VALUES
    ('sondes', 'probes.run', '{"batch": 20}'::jsonb, 60),
    ('purge-sondes', 'probes.purge', '{"retention_days": 30}'::jsonb, 86400)
ON CONFLICT (name) DO NOTHING;
