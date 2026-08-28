-- ============================================================================
--  Jetons à usage unique : vérification d'e-mail et réinitialisation de mot
--  de passe.
--
--  Écrit de façon idempotente (IF NOT EXISTS / DO) afin de pouvoir être
--  rejoué sur une base existante autant qu'exécuté à l'initialisation.
-- ============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- Généralisation du journal anti-bruteforce : il ne couvre plus seulement la
-- connexion, mais aussi les demandes de réinitialisation (une action très
-- sollicitée par les attaquants pour énumérer les comptes ou spammer).
-- ---------------------------------------------------------------------------
ALTER TABLE login_attempts
    ADD COLUMN IF NOT EXISTS action VARCHAR(30) NOT NULL DEFAULT 'login';

COMMENT ON COLUMN login_attempts.action IS 'login | password_reset | email_verification';

CREATE INDEX IF NOT EXISTS login_attempts_action_email_idx
    ON login_attempts (action, email, attempted_at DESC);

CREATE INDEX IF NOT EXISTS login_attempts_action_ip_idx
    ON login_attempts (action, ip_address, attempted_at DESC);


-- ---------------------------------------------------------------------------
-- Type de jeton
-- ---------------------------------------------------------------------------
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'user_token_type') THEN
        CREATE TYPE user_token_type AS ENUM ('email_verification', 'password_reset');
    END IF;
END
$$;


-- ---------------------------------------------------------------------------
-- user_tokens
--
-- Même principe de sécurité que refresh_tokens : la valeur en clair part
-- uniquement dans le lien envoyé par e-mail, seule son empreinte SHA-256 est
-- stockée. Une lecture de la base ne permet donc pas de forger un lien de
-- réinitialisation.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_tokens (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id     UUID            NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type        user_token_type NOT NULL,
    token_hash  CHAR(64)        NOT NULL,      -- SHA-256 hexadécimal
    expires_at  TIMESTAMPTZ     NOT NULL,
    used_at     TIMESTAMPTZ,                   -- usage unique : non NULL = consommé
    ip_address  INET,
    created_at  TIMESTAMPTZ     NOT NULL DEFAULT NOW(),

    CONSTRAINT user_tokens_hash_unique UNIQUE (token_hash)
);

COMMENT ON TABLE user_tokens IS 'Jetons à usage unique (vérification e-mail, réinitialisation) — stockés hachés';

-- Index partiel : seules les demandes encore exploitables sont recherchées.
CREATE INDEX IF NOT EXISTS user_tokens_pending_idx
    ON user_tokens (user_id, type, expires_at)
    WHERE used_at IS NULL;

COMMIT;
