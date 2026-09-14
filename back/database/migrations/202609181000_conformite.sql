-- ============================================================================
--  Conformité : ce qui est gardé, combien de temps, et ce qui part avec un compte
--
--  ---------------------------------------------------------------------------
--  UNE DURÉE ANNONCÉE EST UNE DURÉE APPLIQUÉE
--
--  Les conditions générales annonçaient que les tentatives de connexion étaient
--  conservées trente jours. Aucune purge ne les effaçait : l'affirmation était
--  fausse depuis le premier jour. Même constat pour l'historique, les erreurs
--  reçues, les invitations, les liens de confirmation, la corbeille.
--
--  Chaque durée publiée dans la politique de confidentialité
--  (front/src/utils/legal.js) a désormais sa tâche planifiée ci-dessous, et
--  chaque tâche son test (tests/Integration/RetentionTest.php).
--
--  ---------------------------------------------------------------------------
--  UN COMPTE SUPPRIMÉ NE LAISSE PAS SON NOM DANS L'HISTORIQUE
--
--  « actor_id » passait à NULL, mais « actor_name » — figé au moment du fait —
--  gardait le nom de la personne indéfiniment. Le déclencheur le remplace au
--  moment même de la suppression, en base : une suppression faite en psql
--  n'y échappe pas davantage qu'une suppression faite depuis l'écran.
-- ============================================================================


CREATE OR REPLACE FUNCTION anonymiser_journal() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    UPDATE activity SET actor_name = 'Compte supprimé' WHERE actor_id = OLD.id;

    RETURN OLD;
END;
$$;

CREATE TRIGGER users_anonymiser_journal
    BEFORE DELETE ON users
    FOR EACH ROW EXECUTE FUNCTION anonymiser_journal();

-- Les comptes déjà supprimés : un fait avec un nom mais sans compte vient
-- forcément d'eux — les faits sans auteur (worker, clé de service) n'ont jamais
-- porté de nom.
UPDATE activity
   SET actor_name = 'Compte supprimé'
 WHERE actor_id IS NULL
   AND actor_name IS NOT NULL
   AND actor_name <> 'Compte supprimé';


-- ---------------------------------------------------------------------------
-- Les purges, une par durée publiée
-- ---------------------------------------------------------------------------
INSERT INTO scheduled_tasks (name, type, payload, interval_seconds)
VALUES
    ('purge-connexions',    'login_attempts.purge', '{"retention_days": 30}'::jsonb,  86400),
    ('purge-journal',       'activity.purge',       '{"retention_days": 365}'::jsonb, 86400),
    ('purge-evenements',    'error_events.purge',   '{"retention_days": 90}'::jsonb,  86400),
    ('purge-file',          'jobs.purge',           '{"retention_days": 30}'::jsonb,  86400),
    ('purge-invitations',   'invitations.purge',    '{"retention_days": 30}'::jsonb,  86400),
    ('purge-jetons-compte', 'user_tokens.purge',    '{"retention_days": 7}'::jsonb,   86400),
    ('purge-presence',      'presence.purge',       '{}'::jsonb,                      3600),
    ('purge-corbeille',     'trash.purge',          '{"retention_days": 30}'::jsonb,  86400)
ON CONFLICT (name) DO NOTHING;
