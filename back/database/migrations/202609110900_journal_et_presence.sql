-- ============================================================================
--  Le journal d'activité, le jeton de version, et qui regarde quoi
--
--  ---------------------------------------------------------------------------
--  CE QUE LE JALON PRÉCÉDENT A OUVERT SANS LE REFERMER
--
--  Les tickets sont devenus un bien d'équipe. Deux conséquences sont restées
--  sans réponse :
--
--    1. Rien ne circule. Alice déplace une carte, l'écran de Bob l'ignore
--       jusqu'à ce qu'il change d'onglet. « Deux onglets divergeaient en
--       silence » disait déjà useRevalidate — il parlait d'une personne.
--
--    2. La dernière écriture gagne, sans bruit. Alice et Bob écrivent la même
--       description : l'une des deux disparaît, et rien nulle part n'en garde
--       la trace.
--
--  Cette migration pose les trois choses qui referment les deux :
--
--    activity  — ce qui s'est passé, et qui SERT DE SOURCE au flux
--    version   — de quoi refuser une écriture fondée sur du périmé
--    presence  — qui regarde quoi, en ce moment
-- ============================================================================

-- ===========================================================================
--  1. activity — le journal
-- ===========================================================================
--
-- ┌─────────────────────────────────────────────────────────────────────────┐
-- │  UNE SOURCE, PAS UN DOUBLON                                             │
-- │                                                                         │
-- │  La tentation serait d'écrire l'historique d'un côté et de diffuser les │
-- │  changements de l'autre. Deux chemins pour le même fait, qui            │
-- │  divergeraient au premier oubli : un changement diffusé mais absent du  │
-- │  journal, ou l'inverse.                                                 │
-- │                                                                         │
-- │  Ici, une seule table. Le fil d'activité la LIT, le flux temps réel la  │
-- │  SUIT. Ce que l'un montre, l'autre l'a forcément vu passer.             │
-- └─────────────────────────────────────────────────────────────────────────┘
CREATE TABLE activity (
    -- BIGSERIAL et non UUID : c'est un CURSEUR autant qu'une clé. Le flux
    -- demande « ce qui suit le numéro N », ce qu'un identifiant aléatoire ne
    -- permettrait pas d'exprimer.
    id              BIGSERIAL   PRIMARY KEY,

    organization_id UUID        NOT NULL REFERENCES organizations (id) ON DELETE CASCADE,

    -- L'auteur, et son nom FIGÉ au moment du fait.
    --
    -- Le nom est recopié à dessein, contre toutes les habitudes de
    -- normalisation : un journal qui se réécrit quand un compte est renommé
    -- ou supprimé n'est plus un journal. « Bob a supprimé la table clients »
    -- doit rester lisible le jour où Bob n'est plus là.
    actor_id        UUID        REFERENCES users (id) ON DELETE SET NULL,
    actor_name      VARCHAR(120),

    module          VARCHAR(32) NOT NULL,
    action          VARCHAR(32) NOT NULL,

    -- Ce sur quoi ça porte. Le titre est figé pour la même raison que le nom :
    -- l'entrée doit rester lisible après un renommage, et après une
    -- suppression définitive.
    subject_id      UUID        NOT NULL,
    subject_ref     VARCHAR(60),
    subject_title   VARCHAR(200),

    -- { champ: [avant, après] } — ce qui a changé, et rien d'autre. C'est ce
    -- qui permet à un écran distant d'appliquer la modification sans relire
    -- la ligne entière, et à l'écran de conflit de nommer le champ en cause.
    changes         JSONB       NOT NULL DEFAULT '{}',

    -- ┌───────────────────────────────────────────────────────────────────────┐
    -- │  LA COLONNE QUI REND LE CONFLIT PRÉCIS PLUTÔT QUE GLOBAL             │
    -- │                                                                       │
    -- │  Version du sujet APRÈS ce fait. Elle permet de répondre à la seule   │
    -- │  question qui compte devant une écriture périmée : « depuis la        │
    -- │  version que ce client détient, QUELS CHAMPS quelqu'un d'autre a-t-il │
    -- │  touchés ? »                                                          │
    -- │                                                                       │
    -- │  Sans elle, il ne resterait que la réponse grossière — « la ressource │
    -- │  a changé, rechargez » — et l'on perdrait le texte en cours de saisie │
    -- │  pour un conflit qui, neuf fois sur dix, ne porte pas sur le champ    │
    -- │  qu'on est en train d'écrire.                                         │
    -- │                                                                       │
    -- │  NULL pour les sujets sans jeton de version : seuls les tickets en    │
    -- │  portent un pour l'instant.                                           │
    -- └───────────────────────────────────────────────────────────────────────┘
    subject_version INTEGER,

    -- ┌───────────────────────────────────────────────────────────────────────┐
    -- │  LE PIÈGE DES CURSEURS SUR SÉQUENCE, ET SA PARADE                    │
    -- │                                                                       │
    -- │  Une séquence attribue les numéros à l'INSERTION, pas à la validation.│
    -- │  Deux transactions concurrentes prennent 5 et 6 ; si 6 valide en      │
    -- │  premier, un lecteur avance son curseur à 6 — et ne verra JAMAIS le   │
    -- │  5, validé une milliseconde plus tard.                                │
    -- │                                                                       │
    -- │  L'événement d'un coéquipier disparaîtrait sans trace, exactement le  │
    -- │  défaut que ce jalon vient corriger ailleurs.                         │
    -- │                                                                       │
    -- │  D'où cette colonne : le numéro de transaction. Le lecteur ne prend   │
    -- │  que ce qui vient de transactions PLUS ANCIENNES que la plus vieille  │
    -- │  encore en cours — au-delà, plus rien ne peut s'intercaler.           │
    -- │  Cf. ActivityRepository::since().                                      │
    -- └───────────────────────────────────────────────────────────────────────┘
    xact_id         BIGINT      NOT NULL DEFAULT pg_current_xact_id()::text::bigint,

    happened_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  activity         IS 'Ce qui s''est passé — source unique du fil d''activité ET du flux temps réel';
COMMENT ON COLUMN activity.xact_id IS 'Numéro de transaction : garantit qu''aucun événement ne se glisse derrière un curseur déjà avancé';

-- L'index du flux : l'espace en tête parce qu'il filtre toujours, le curseur
-- ensuite parce que c'est la borne demandée.
CREATE INDEX activity_flux_idx ON activity (organization_id, id);

-- Celui du fil d'activité, qui lit à l'envers.
CREATE INDEX activity_recent_idx ON activity (organization_id, happened_at DESC);

-- Celui du conflit : « ce qui est arrivé à CE sujet depuis telle version ».
CREATE INDEX activity_sujet_idx ON activity (subject_id, id DESC);


-- ===========================================================================
--  2. version — de quoi reconnaître une écriture périmée
-- ===========================================================================
--
-- ┌─────────────────────────────────────────────────────────────────────────┐
-- │  POURQUOI PAS « updated_at »                                            │
-- │                                                                         │
-- │  Il existe déjà et semblerait suffire. Deux raisons de ne pas s'en      │
-- │  servir : deux écritures dans la même microseconde portent la même      │
-- │  date, et une horloge se compare mal à travers un client, un fuseau et  │
-- │  une sérialisation JSON.                                                │
-- │                                                                         │
-- │  Un entier qui monte n'a aucun de ces défauts. Il ne dit pas QUAND,     │
-- │  seulement COMBIEN DE FOIS — et c'est exactement la question posée.     │
-- └─────────────────────────────────────────────────────────────────────────┘
--
-- Sur les tickets SEULS : c'est le module-patron, toute mécanique transverse
-- s'y éprouve avant d'être répliquée. Le déclencheur ci-dessous est écrit une
-- fois pour toutes et se pose sur n'importe quelle table portant la colonne.
ALTER TABLE tickets ADD COLUMN version INTEGER NOT NULL DEFAULT 1;

COMMENT ON COLUMN tickets.version IS 'Incrémenté à chaque écriture — jeton de concurrence, jamais fourni par le client';

CREATE OR REPLACE FUNCTION bump_version()
RETURNS TRIGGER AS $fn$
BEGIN
    -- L'ancienne valeur + 1, et non NEW.version + 1 : un client qui aurait
    -- glissé « version » dans son corps de requête ne doit pas pouvoir
    -- choisir le prochain numéro.
    NEW.version = OLD.version + 1;

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;

CREATE TRIGGER tickets_bump_version
    BEFORE UPDATE ON tickets
    FOR EACH ROW EXECUTE FUNCTION bump_version();


-- ===========================================================================
--  3. presence — qui regarde quoi
-- ===========================================================================
--
-- Une ligne par compte et par espace, ÉCRASÉE à chaque battement. Pas
-- d'historique : la présence est un instantané, et conserver les positions
-- passées de chacun serait une surveillance, pas une aide.
--
-- Elle est mise à jour par le sondage du flux lui-même, qui passe en même
-- temps « où je suis » et repart avec « qui d'autre est là ». Aucun appel
-- supplémentaire : une présence qui coûterait une requête de plus serait la
-- première chose à couper le jour où l'on cherche à alléger.
CREATE TABLE presence (
    organization_id UUID        NOT NULL REFERENCES organizations (id) ON DELETE CASCADE,
    user_id         UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,

    -- L'écran, et éventuellement ce qui y est ouvert. « tickets » suffit à
    -- dire « il est sur le tableau » ; le sujet dit « il a CE ticket ouvert »,
    -- ce qui est l'information qui évite une collision.
    screen          VARCHAR(40) NOT NULL,
    subject_id      UUID,

    seen_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    PRIMARY KEY (organization_id, user_id)
);

COMMENT ON TABLE presence IS 'Instantané de qui regarde quoi — écrasé à chaque battement, sans historique';

-- Les présences périmées sont exclues à la LECTURE, par une borne de temps,
-- plutôt que supprimées par une tâche de fond : une ligne obsolète ne gêne
-- personne, et un travail périodique de plus se justifierait mal pour ça.
CREATE INDEX presence_fraiche_idx ON presence (organization_id, seen_at DESC);
