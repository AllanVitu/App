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
-- La table générique module_items n'est PLUS peuplée.
--
-- Les cinq modules ont chacun leur propre table : y écrire des lignes de
-- démonstration créerait des données qu'aucun écran n'affiche, et le fil
-- d'activité mènerait vers des modules où elles n'existent pas. La table
-- reste en place — elle sert de repli à un module ajouté en base sans code
-- dédié — mais elle démarre vide, ce qui est son état normal.
-- ---------------------------------------------------------------------------

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


-- ---------------------------------------------------------------------------
-- MODULE BACKEND — schémas de données et clés d'API
--
-- Une table est volontairement laissée SANS sécurité au niveau ligne : c'est
-- le seul signal d'alerte du module, et un jeu de démonstration où tout va
-- bien ne montrerait jamais à quoi ressemble une alerte.
-- ---------------------------------------------------------------------------
INSERT INTO backend_tables (user_id, name, description, columns, rls_enabled, row_estimate)
SELECT u.id, v.name, v.description, v.columns::jsonb, v.rls, v.rows
FROM (VALUES
    ('users', 'Comptes applicatifs, adresse unique insensible à la casse.',
     '[{"name":"id","type":"uuid","nullable":false},
       {"name":"email","type":"text","nullable":false},
       {"name":"password_hash","type":"text","nullable":false},
       {"name":"created_at","type":"timestamptz","nullable":false}]', TRUE, 1240),
    ('posts', 'Publications, rattachées à leur auteur.',
     '[{"name":"id","type":"uuid","nullable":false},
       {"name":"author_id","type":"uuid","nullable":false},
       {"name":"title","type":"varchar","nullable":false},
       {"name":"body","type":"text","nullable":true},
       {"name":"published_at","type":"timestamptz","nullable":true}]', TRUE, 87),
    ('media', 'Pièces jointes. Politiques d''accès encore à écrire.',
     '[{"name":"id","type":"uuid","nullable":false},
       {"name":"path","type":"text","nullable":false},
       {"name":"size","type":"integer","nullable":false}]', FALSE, 512),
    ('audit_log', 'Journal des actions sensibles, conservé un an.',
     '[{"name":"id","type":"uuid","nullable":false},
       {"name":"actor_id","type":"uuid","nullable":true},
       {"name":"payload","type":"jsonb","nullable":false},
       {"name":"occurred_at","type":"timestamptz","nullable":false}]', TRUE, 9302)
) AS v(name, description, columns, rls, rows)
CROSS JOIN (SELECT id FROM users WHERE email = 'demo@saas.local') AS u
WHERE NOT EXISTS (SELECT 1 FROM backend_tables b WHERE b.user_id = u.id);

-- Clés d'API. Les empreintes sont aléatoires : aucune clé de démonstration
-- ne doit être devinable, même dans un jeu de développement.
INSERT INTO backend_api_keys (user_id, label, scope, token_prefix, token_hash, revoked_at, last_used_at)
SELECT
    u.id,
    v.label,
    v.scope::api_key_scope,
    v.prefix,
    encode(sha256(gen_random_uuid()::text::bytea), 'hex'),
    CASE WHEN v.revoked THEN NOW() - INTERVAL '3 days' ELSE NULL END,
    CASE WHEN v.revoked THEN NULL ELSE NOW() - INTERVAL '2 hours' END
FROM (VALUES
    ('Client web',        'anon',    'pk_9f3c1a7d', FALSE),
    ('Tâches planifiées', 'service', 'sk_4b8e2c05', FALSE),
    ('Ancienne intégration', 'service', 'sk_1d7a93f2', TRUE)
) AS v(label, scope, prefix, revoked)
CROSS JOIN (SELECT id FROM users WHERE email = 'demo@saas.local') AS u
WHERE NOT EXISTS (SELECT 1 FROM backend_api_keys k WHERE k.user_id = u.id);


-- ---------------------------------------------------------------------------
-- MODULE DÉPLOIEMENT
--
-- created_at ET finished_at sont fournis explicitement : le trigger conserve
-- une date de fin déjà posée et en déduit la durée. Les laisser à NOW()
-- donnerait à tous les déploiements une durée de quelques millisecondes.
-- ---------------------------------------------------------------------------
INSERT INTO deployments (
    user_id, environment, branch, commit_sha, commit_message, status, url, log, created_at, finished_at
)
SELECT
    u.id,
    v.env::deployment_env,
    v.branch,
    v.sha,
    v.message,
    v.status::deployment_status,
    v.url,
    v.log,
    NOW() - make_interval(mins => v.ago_min),
    CASE
        WHEN v.status IN ('queued', 'building') THEN NULL
        ELSE NOW() - make_interval(mins => v.ago_min) + make_interval(secs => v.secs)
    END
FROM (VALUES
    ('production', 'main',            'a3f9c1d8b2e4', 'Module Tickets : modèle métier propre',
     'ready',    'https://app.exemple.dev',              E'Installation des dépendances…\nCompilation…\nDéploiement terminé.', 42,  74),
    ('preview',    'feat/spirale',     '7e21b4c9f0aa', 'Galerie des modules : spirale ou liste',
     'ready',    'https://spirale.preview.exemple.dev',  E'Compilation…\nDéploiement terminé.',                                180, 61),
    ('preview',    'fix/refresh-token', 'c40d8e1a5b73', 'Détection de réutilisation des jetons',
     'error',    NULL,                                   E'Compilation…\nÉchec : 2 tests en échec.\n  AuthTest::rotation',     95,  38),
    ('production', 'main',            'b18f6d3c9e02', 'En-têtes de sécurité en production',
     'ready',    'https://app.exemple.dev',              E'Compilation…\nDéploiement terminé.',                                1440, 68),
    ('preview',    'feat/geist',       'd92a7f04c1bb', 'Typographie : Geist et Geist Mono',
     'building', NULL,                                   E'Installation des dépendances…',                                     3,   0),
    ('preview',    'chore/seed',       'f5b0c28e7d41', 'Ordre de chargement de la base',
     'canceled', NULL,                                   E'Annulé par un nouveau déploiement sur la même branche.',            310, 12),
    ('production', 'main',            'e73c1b9a4d02', 'Consentement aux conditions générales',
     'ready',    'https://app.exemple.dev',              E'Compilation…\nDéploiement terminé.',                                4320, 71)
) AS v(env, branch, sha, message, status, url, log, ago_min, secs)
CROSS JOIN (SELECT id FROM users WHERE email = 'demo@saas.local') AS u
WHERE NOT EXISTS (SELECT 1 FROM deployments d WHERE d.user_id = u.id);


-- ---------------------------------------------------------------------------
-- MODULE SUPERVISION
--
-- Les groupes sont créés d'abord, leurs occurrences ensuite : c'est le
-- trigger qui incrémente le compteur et remonte la date de dernière vue.
-- Écrire ces valeurs à la main les ferait diverger du contenu réel.
-- ---------------------------------------------------------------------------
INSERT INTO error_groups (user_id, fingerprint, title, culprit, level, first_seen_at)
SELECT u.id, v.fingerprint, v.title, v.culprit, v.level::error_level,
       NOW() - make_interval(days => v.first_days)
FROM (VALUES
    ('a1f0c3d29b47', 'TypeError: Cannot read properties of undefined (reading ''slug'')',
     'ModuleGallery.vue:247', 'error',   6),
    ('b7e21d84a0c9', 'PDOException: SQLSTATE[42703] column "terms_accepted_at" does not exist',
     'UserRepository::create', 'fatal',  2),
    ('c39a5f7e1b02', 'RangeError: Maximum call stack size exceeded',
     'useGsapContext.js:34',  'error',   9),
    ('d02b6c1a8f35', 'Warning: la police Geist Mono n''a pas pu être chargée',
     'main.css',              'warning', 1)
) AS v(fingerprint, title, culprit, level, first_days)
CROSS JOIN (SELECT id FROM users WHERE email = 'demo@saas.local') AS u
WHERE NOT EXISTS (SELECT 1 FROM error_groups g WHERE g.user_id = u.id);

-- Occurrences : generate_series produit N répétitions par groupe, réparties
-- sur les jours précédents pour que la courbe ait un relief.
INSERT INTO error_events (group_id, user_id, message, stack, context, occurred_at)
SELECT
    g.id,
    g.user_id,
    g.title,
    E'at ' || g.culprit || E'\n  at handler (app.js:118)\n  at dispatch (kernel.js:42)',
    jsonb_build_object('release', 'v1.0.0', 'occurrence', n),
    NOW() - make_interval(hours => (n * 7) % 240)
FROM error_groups g
CROSS JOIN LATERAL generate_series(
    1,
    CASE g.level WHEN 'fatal' THEN 23 WHEN 'error' THEN 11 ELSE 4 END
) AS n
WHERE g.user_id = (SELECT id FROM users WHERE email = 'demo@saas.local')
  AND NOT EXISTS (SELECT 1 FROM error_events e WHERE e.group_id = g.id);

-- Une erreur résolue, pour que les trois statuts existent dans la démo.
-- Faite APRÈS les occurrences : le trigger rouvre tout groupe résolu qui
-- reçoit une nouvelle occurrence, l'ordre inverse annulerait ce statut.
UPDATE error_groups
   SET status = 'resolved'
 WHERE fingerprint = 'c39a5f7e1b02'
   AND user_id = (SELECT id FROM users WHERE email = 'demo@saas.local');


-- ---------------------------------------------------------------------------
-- MODULE DESIGN
--
-- Le numéro de version est posé PAR FICHIER par un trigger : les versions
-- sont donc insérées sans numéro, dans l'ordre voulu.
-- ---------------------------------------------------------------------------
INSERT INTO design_files (user_id, name, kind, description, accent)
SELECT u.id, v.name, v.kind::design_kind, v.description, v.accent
FROM (VALUES
    ('Poste de travail',      'maquette',  'Cadre général : menu, chemin, contenu, barre d''état.', '#7ee2a8'),
    ('Système de composants', 'systeme',   'Boutons, champs, pastilles et états de saisie.',        '#d8b26a'),
    ('Suivi de tickets',      'prototype', 'Parcours clavier complet, de la création à la clôture.', '#8ab4f8'),
    ('Écrans publics',        'maquette',  'Connexion, inscription, mot de passe oublié.',          '#c98a7a')
) AS v(name, kind, description, accent)
CROSS JOIN (SELECT id FROM users WHERE email = 'demo@saas.local') AS u
WHERE NOT EXISTS (SELECT 1 FROM design_files f WHERE f.user_id = u.id);

INSERT INTO design_versions (file_id, user_id, label, notes, created_at)
SELECT f.id, f.user_id, v.label, v.notes, NOW() - make_interval(days => v.ago)
FROM design_files f
CROSS JOIN (VALUES
    ('Première intention', 'Structure posée, sans couleur.',            12),
    ('Passe typographique', 'Échelle de titres, interlettrage resserré.', 5),
    ('Thème sombre',       'Transposition complète, contrastes vérifiés.', 1)
) AS v(label, notes, ago)
WHERE f.user_id = (SELECT id FROM users WHERE email = 'demo@saas.local')
  AND NOT EXISTS (SELECT 1 FROM design_versions dv WHERE dv.file_id = f.id)
ORDER BY v.ago DESC;

COMMIT;
