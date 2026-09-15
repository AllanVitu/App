-- ============================================================================
--  File de tâches et travaux périodiques
--
--  PREMIÈRE MIGRATION DU PROJET. Tout ce qui précède vient de « init/*.sql »,
--  joué par PostgreSQL à la création du volume ; ce répertoire porte
--  désormais tout ce qui vient après.
--
--  ---------------------------------------------------------------------------
--  POURQUOI UNE FILE
--
--  L'application n'avait aucun processus de fond, et c'était la cause commune
--  de quatre manques qu'on traitait séparément :
--
--    - les e-mails partaient DANS la requête HTTP. Une inscription attendait
--      le serveur SMTP ; un serveur lent rendait l'inscription lente, et un
--      serveur muet la faisait expirer.
--    - rien ne purgeait les jetons révoqués — 2 082 lignes accumulées sur une
--      base de développement.
--    - les notifications périodiques étaient impossibles, ce que l'écran des
--      paramètres disait déjà en toutes lettres.
--    - un envoi échoué était perdu, sans relance.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- Les tâches
-- ---------------------------------------------------------------------------
CREATE TABLE jobs (
    id           uuid         PRIMARY KEY DEFAULT gen_random_uuid(),

    -- Le TYPE dit quoi faire, la CHARGE dit avec quoi. Le worker n'a pas à
    -- connaître le métier : il rend le type à un gestionnaire déclaré.
    type         varchar(100) NOT NULL,
    payload      jsonb        NOT NULL DEFAULT '{}'::jsonb,

    -- Date à partir de laquelle la tâche devient exécutable. C'est ce champ
    -- qui porte à la fois le différé volontaire et le recul entre deux essais.
    available_at timestamptz  NOT NULL DEFAULT NOW(),

    -- Prise par un worker, et quand. Une tâche réservée depuis trop longtemps
    -- est reprise : un worker tué en plein travail ne doit pas la retenir.
    reserved_at  timestamptz,

    attempts     smallint     NOT NULL DEFAULT 0,
    max_attempts smallint     NOT NULL DEFAULT 3,

    -- Renseignés ensemble quand la tâche a épuisé ses essais. Elle reste en
    -- table : une tâche échouée qu'on efface est une panne qu'on ne verra
    -- jamais.
    failed_at    timestamptz,
    last_error   text,

    created_at   timestamptz  NOT NULL DEFAULT NOW()
);

-- L'index du CHEMIN CHAUD : « la prochaine tâche à prendre ». Partiel, donc
-- il ne porte que les tâches réellement candidates — les réservées et les
-- échouées n'y figurent pas, et la file reste petite même si la table grossit.
CREATE INDEX jobs_prochaine_idx
    ON jobs (available_at)
 WHERE reserved_at IS NULL AND failed_at IS NULL;

-- Reprise des tâches abandonnées par un worker mort.
CREATE INDEX jobs_reservees_idx ON jobs (reserved_at) WHERE reserved_at IS NOT NULL;

COMMENT ON TABLE jobs IS 'File de tâches exécutées hors requête HTTP par bin/worker.php';

-- ---------------------------------------------------------------------------
-- Les travaux périodiques
-- ---------------------------------------------------------------------------
--
-- Une table plutôt qu'un cron système : le planificateur vit dans le même
-- processus que le worker, donc dans la même image, avec les mêmes variables
-- d'environnement et le même accès à la base. Un cron dans le conteneur
-- demanderait de dupliquer tout cela — et resterait invisible depuis
-- l'application.
--
-- « last_run_at » porte l'état : deux workers qui tournent en parallèle ne
-- déclencheront pas deux fois le même travail, la mise à jour étant
-- conditionnée à l'échéance dans la même requête.
CREATE TABLE scheduled_tasks (
    name             varchar(100) PRIMARY KEY,
    type             varchar(100) NOT NULL,
    payload          jsonb        NOT NULL DEFAULT '{}'::jsonb,
    interval_seconds integer      NOT NULL CHECK (interval_seconds > 0),
    last_run_at      timestamptz,
    created_at       timestamptz  NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE scheduled_tasks IS 'Travaux récurrents, mis en file par le planificateur';

-- ---------------------------------------------------------------------------
-- Le premier travail périodique, et il répond à un défaut constaté
-- ---------------------------------------------------------------------------
--
-- Les jetons de rafraîchissement révoqués ou expirés restent en table sans
-- rien servir. La rotation en produit un à chaque rafraîchissement : sur un
-- compte actif, c'est plusieurs lignes par jour, indéfiniment.
--
-- Quatorze jours de conservation après expiration : de quoi enquêter sur une
-- session suspecte, pas de quoi garder l'historique complet d'un compte.
INSERT INTO scheduled_tasks (name, type, payload, interval_seconds)
VALUES (
    'purge-jetons',
    'tokens.purge',
    '{"retention_days": 14}'::jsonb,
    86400
);

