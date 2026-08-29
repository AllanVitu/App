-- ============================================================================
--  SaaS Starter — Jeu de données de DÉVELOPPEMENT
--
--  Ce fichier ne vit PAS dans back/database/init/ : il n'est donc jamais
--  chargé automatiquement. docker-compose le monte dans le dossier
--  d'initialisation via la variable DB_SEED (valeur par défaut « dev.sql »).
--  Pour un déploiement : DB_SEED=none.sql — aucun compte de démonstration
--  n'est alors créé.
--
--  Compte de démonstration :
--     e-mail        demo@saas.local
--     mot de passe  Password123!
--
--  Le hash ci-dessous a été produit par
--  password_hash('Password123!', PASSWORD_BCRYPT, ['cost' => 12]).
--  Les préférences et l'attribution des modules sont créées automatiquement
--  par le trigger users_provision_defaults (cf. 01_schema.sql).
-- ============================================================================

-- ---------------------------------------------------------------------------
-- Garde-fou : seconde barrière, indépendante de la configuration Docker.
-- Si APP_ENV vaut « production », le script échoue bruyamment plutôt que de
-- créer silencieusement un compte dont le mot de passe est public.
-- ---------------------------------------------------------------------------
\getenv app_env APP_ENV
\if :{?app_env}
\else
\set app_env development
\endif

BEGIN;

SET LOCAL saas.app_env = :'app_env';

DO $guard$
BEGIN
    IF current_setting('saas.app_env', true) = 'production' THEN
        RAISE EXCEPTION
            'Jeu de données de démonstration refusé : APP_ENV=production. Utilisez DB_SEED=none.sql.';
    END IF;
END
$guard$;

INSERT INTO users (
    email, password_hash, full_name, role, email_verified_at,
    terms_accepted_at, terms_accepted_version
)
VALUES (
    'demo@saas.local',
    '$2y$12$VOlgNc0A3bI9WJ4LifsbDuocgK7.5kbuwe3d3/OQgnEg.gmu/F76G',
    'Utilisateur Démo',
    'admin',
    NOW(),
    -- Le compte de démonstration a « accepté » : sans cela, il serait
    -- redirigé vers l'écran de consentement à chaque connexion, y compris
    -- pendant les tests de bout en bout.
    NOW(),
    '1.0'
)
ON CONFLICT (email) DO NOTHING;

-- ---------------------------------------------------------------------------
-- Quelques enregistrements de démonstration, répartis sur les modules.
-- Les identifiants sont résolus par sous-requête : aucun UUID en dur.
-- ---------------------------------------------------------------------------
INSERT INTO module_items (module_id, user_id, title, description, status, data, position)
SELECT
    m.id,
    u.id,
    v.title,
    v.description,
    v.status::item_status,
    v.data::jsonb,
    v.position
FROM (VALUES
    ('backend',     'Schéma des utilisateurs',   'Table, contraintes et politiques d''accès.',      'active',   '{"priority":"high","tags":["schema"]}', 10),
    ('backend',     'Stockage des pièces jointes', 'Compartiment à créer, quotas à définir.',       'draft',    '{"priority":"low"}',                    20),
    ('deploiement', 'Environnement de préproduction', 'Une URL par branche, variables à câbler.',   'active',   '{"branch":"main"}',                     10),
    ('tickets',     'Refonte de la navigation',  'Découpé en trois lots, premier lot livré.',       'active',   '{"priority":"medium"}',                 10),
    ('supervision', 'Alerte sur les 500',        'Seuil trop bas : trop de notifications.',         'archived', '{"threshold":5}',                       10),
    ('design',      'Système de composants',     'Boutons et champs harmonisés, reste les tableaux.', 'active', '{"priority":"high"}',                   10)
) AS v(module_slug, title, description, status, data, position)
JOIN modules m ON m.slug = v.module_slug
CROSS JOIN (SELECT id FROM users WHERE email = 'demo@saas.local') AS u
ON CONFLICT DO NOTHING;

COMMIT;
