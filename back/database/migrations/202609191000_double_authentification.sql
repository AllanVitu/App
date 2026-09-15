-- ============================================================================
--  Double authentification : un mot de passe volé ne suffit plus
--
--  ---------------------------------------------------------------------------
--  LE SECRET N'EST JAMAIS EN CLAIR
--
--  Le secret TOTP permet de fabriquer tous les codes à venir : lu dans une
--  copie de la base — une sauvegarde égarée suffit —, il annule la protection
--  qu'il est censé apporter. Il est donc CHIFFRÉ (libsodium, clé dérivée côté
--  serveur, cf. App\Services\SecretBox) : la base seule ne le livre pas.
--
--  ---------------------------------------------------------------------------
--  UN CODE NE SERT QU'UNE FOIS
--
--  Un code reste valable trente secondes, et même un peu plus, par tolérance
--  d'horloge. Intercepté, il pourrait resservir dans la foulée. Le dernier pas
--  de temps accepté est gardé, et tout code d'un pas antérieur ou égal est
--  refusé — la réservation est atomique : deux requêtes simultanées avec le
--  même code n'ouvrent pas deux sessions.
--
--  ---------------------------------------------------------------------------
--  LE MOT DE PASSE N'OUVRE QU'UN DÉFI
--
--  À la connexion d'un compte protégé, le mot de passe correct ouvre un défi :
--  un jeton à usage unique, valable cinq minutes, limité à cinq essais, dont
--  la base ne garde que l'empreinte. La session ne s'ouvre qu'avec le code.
-- ============================================================================


ALTER TABLE users
    ADD COLUMN two_factor_secret         TEXT,
    ADD COLUMN two_factor_pending_secret TEXT,
    ADD COLUMN two_factor_enabled_at     TIMESTAMPTZ,
    ADD COLUMN two_factor_last_step      BIGINT;

COMMENT ON COLUMN users.two_factor_secret IS 'Secret TOTP CHIFFRÉ (cf. SecretBox) — jamais en clair, jamais exposé';
COMMENT ON COLUMN users.two_factor_pending_secret IS 'Secret en attente de confirmation par un premier code, chiffré lui aussi';
COMMENT ON COLUMN users.two_factor_last_step IS 'Dernier pas de temps accepté : un code ne sert qu''une fois';

-- Un secret actif va avec une date d'activation, et réciproquement.
ALTER TABLE users
    ADD CONSTRAINT users_two_factor_coherent
    CHECK ((two_factor_secret IS NULL) = (two_factor_enabled_at IS NULL));


-- Les codes de secours : dix, affichés une seule fois, gardés en empreinte.
CREATE TABLE two_factor_recovery_codes (
    id         UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id    UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    code_hash  CHAR(64)    NOT NULL,
    used_at    TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT two_factor_recovery_codes_unique UNIQUE (user_id, code_hash)
);


-- Les défis de connexion : l'étape entre le mot de passe et le code.
CREATE TABLE two_factor_challenges (
    id         UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id    UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    token_hash CHAR(64)    NOT NULL UNIQUE,
    attempts   SMALLINT    NOT NULL DEFAULT 0,
    expires_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX two_factor_challenges_expiry_idx ON two_factor_challenges (expires_at);


-- Un défi expiré ne sert plus à rien : effacé chaque heure.
INSERT INTO scheduled_tasks (name, type, payload, interval_seconds)
VALUES ('purge-defis-connexion', 'two_factor_challenges.purge', '{}'::jsonb, 3600)
ON CONFLICT (name) DO NOTHING;
