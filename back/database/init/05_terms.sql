-- ============================================================================
--  Acceptation des conditions générales
--
--  Écrit de façon idempotente : rejouable sur une base existante autant
--  qu'exécuté à l'initialisation.
--
--  La date ET la version acceptée sont conservées. Sans le numéro de
--  version, une modification des conditions rendrait la trace inexploitable :
--  on saurait que l'utilisateur a accepté « quelque chose », sans savoir
--  quoi. C'est précisément ce qu'une preuve de consentement doit établir.
-- ============================================================================

BEGIN;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS terms_accepted_at      TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS terms_accepted_version VARCHAR(20);

COMMENT ON COLUMN users.terms_accepted_at IS
    'Horodatage de l''acceptation des conditions générales';
COMMENT ON COLUMN users.terms_accepted_version IS
    'Version des conditions acceptées — indispensable pour savoir à QUOI l''utilisateur a consenti';

-- Les comptes qui n'ont pas encore accepté sont recensés à la connexion :
-- l'index partiel garde cette recherche efficace quand ils deviendront rares.
CREATE INDEX IF NOT EXISTS users_terms_pending_idx
    ON users (id)
    WHERE terms_accepted_at IS NULL;

COMMIT;
