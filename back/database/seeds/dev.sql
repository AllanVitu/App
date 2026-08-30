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
-- Idempotence par NOT EXISTS, et non par ON CONFLICT : module_items n'a aucune
-- contrainte d'unicité sur (titre, module, utilisateur), donc « ON CONFLICT DO
-- NOTHING » n'attrapait rien et rejouer ce fichier créait des doublons.
WHERE NOT EXISTS (SELECT 1 FROM module_items i WHERE i.user_id = u.id);

-- ---------------------------------------------------------------------------
-- Tickets de démonstration.
--
-- Le module « Tickets » a sa propre table : numéro, priorité ordonnée, cycle
-- de vie. Les numéros (#1, #2…) sont attribués par le trigger dans l'ordre
-- d'insertion, d'où le ORDER BY explicite — sans lui, l'ordre des lignes
-- d'un VALUES n'est pas garanti et la numérotation varierait d'une base à
-- l'autre, y compris entre deux exécutions de la suite de tests.
--
-- Les échéances sont RELATIVES à la date du jour : le jeu de démonstration
-- montre toujours des retards et des échéances proches, quelle que soit la
-- date à laquelle la base est créée.
-- ---------------------------------------------------------------------------
INSERT INTO tickets (user_id, title, description, status, priority, project, labels, due_date)
SELECT
    u.id,
    v.title,
    v.description,
    v.status::ticket_status,
    v.priority::ticket_priority,
    v.project,
    v.labels::text[],
    CASE WHEN v.due_in_days IS NULL THEN NULL ELSE CURRENT_DATE + v.due_in_days END
FROM (VALUES
    (1,  'Détecter la réutilisation d''un refresh token',
         'Un jeton présenté deux fois signale un vol : révoquer toute la famille et forcer la reconnexion.',
         'todo',        'urgent', 'Sécurité',    '{securite,auth}',      -2),
    (2,  'Purger les jetons expirés',
         'Aucune tâche de nettoyage : la table grossit indéfiniment.',
         'todo',        'high',   'Sécurité',    '{securite,dette}',      1),
    (3,  'Piéger le focus dans les fenêtres modales',
         'La tabulation sort de la modale et atteint la page derrière.',
         'in_progress', 'high',   'Accessibilité', '{a11y}',              3),
    (4,  'CSP sans unsafe-inline',
         'Les styles en ligne de GSAP imposent la tolérance : passer par une nonce.',
         'backlog',     'medium', 'Sécurité',    '{securite}',         NULL),
    (5,  'Sauvegardes automatiques de PostgreSQL',
         'pg_dump quotidien, rétention 30 jours, restauration à vérifier.',
         'backlog',     'high',   'Exploitation', '{infra}',            NULL),
    (6,  'Journalisation structurée',
         'error_log en texte libre : passer au JSON pour rendre les incidents interrogeables.',
         'backlog',     'low',    'Exploitation', '{infra,dette}',      NULL),
    (7,  'Second facteur TOTP',
         'Applications d''authentification, avec codes de secours.',
         'backlog',     'medium', 'Sécurité',    '{securite}',         NULL),
    (8,  'Changement d''adresse e-mail',
         'Confirmation sur l''ancienne ET la nouvelle adresse.',
         'todo',        'medium', 'Compte',      '{}',                    7),
    (9,  'Corbeille consultable',
         'La suppression est logique mais rien ne permet de restaurer.',
         'backlog',     'low',    'Produit',     '{}',                 NULL),
    (10, 'Écran de repli sur erreur Vue',
         'Le gestionnaire global est en place, l''écran de repli manque.',
         'in_progress', 'medium', 'Produit',     '{}',                    5),
    (11, 'Latence de l''API en développement',
         'OPcache revalidait à chaque inclusion : plancher ramené de 400 ms à 60 ms.',
         'done',        'high',   'Exploitation', '{perf}',              -6),
    (12, 'En-têtes de sécurité en production',
         'Un add_header dans un location annulait ceux hérités : extraits dans un fichier ré-inclus.',
         'done',        'urgent', 'Sécurité',    '{securite}',           -4),
    (13, 'Consentement aux conditions générales',
         'Version et horodatage enregistrés dans la même transaction que la création du compte.',
         'done',        'high',   'Conformité',  '{conformite}',         -3),
    (14, 'Migrer vers une autre bibliothèque d''icônes',
         'Écarté : le jeu actuel couvre tous les usages.',
         'canceled',    'none',   'Produit',     '{}',                 NULL)
) AS v(seq, title, description, status, priority, project, labels, due_in_days)
CROSS JOIN (SELECT id FROM users WHERE email = 'demo@saas.local') AS u
-- Idempotence : rejouer le fichier sur une base déjà peuplée n'ajoute rien.
WHERE NOT EXISTS (SELECT 1 FROM tickets t WHERE t.user_id = u.id)
ORDER BY v.seq;

COMMIT;
