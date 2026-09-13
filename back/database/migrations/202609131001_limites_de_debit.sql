-- ============================================================================
--  Limitation de débit générique
--
--  ---------------------------------------------------------------------------
--  POURQUOI UNE SECONDE TABLE, À CÔTÉ DE « login_attempts »
--
--  « login_attempts » compte des ÉCHECS d'authentification, par adresse et
--  par IP, et un succès les efface : c'est un anti-force brute. Il ne sait pas
--  compter des APPELS — des signalements d'erreur, des inscriptions, des
--  téléversements — dont l'issue n'a rien à voir avec un mot de passe.
--
--  ---------------------------------------------------------------------------
--  LA CLÉ N'EST JAMAIS STOCKÉE EN CLAIR
--
--  Compter par adresse IP n'oblige pas à écrire des adresses IP. Le compteur
--  n'a besoin que de reconnaître la même clé d'un appel à l'autre : une
--  empreinte HMAC y suffit, et une fuite de cette table ne dit ni qui ni
--  d'où (cf. App\Services\RateLimiter).
-- ============================================================================

CREATE TABLE rate_limits (
    -- HMAC-SHA256 de « portée + clé », en hexadécimal.
    bucket       CHAR(64)    NOT NULL,
    window_start TIMESTAMPTZ NOT NULL,
    hits         INTEGER     NOT NULL DEFAULT 1,

    PRIMARY KEY (bucket, window_start),
    CONSTRAINT rate_limits_hits_positive CHECK (hits > 0)
);

-- La purge balaie par date : sans cet index, elle parcourrait la table
-- entière toutes les heures.
CREATE INDEX rate_limits_window_idx ON rate_limits (window_start);

COMMENT ON TABLE rate_limits IS
    'Compteurs d''appels par fenêtre fixe — clés pseudonymisées par HMAC, jamais en clair';

-- Une fenêtre close ne compte plus rien. Toutes les heures, ce qui est fermé
-- depuis plus d'un jour quitte la table.
INSERT INTO scheduled_tasks (name, type, payload, interval_seconds)
VALUES ('purge-limites-de-debit', 'rate_limits.purge', '{}'::jsonb, 3600);
