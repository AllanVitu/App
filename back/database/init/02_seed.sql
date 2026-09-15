-- ============================================================================
--  SaaS Starter — Catalogue des modules
--
--  Joué juste après 01_schema.sql, à la création du volume PostgreSQL.
--  Contient le strict nécessaire au fonctionnement de l'application.
--  (Le compte de démonstration vit dans database/seeds/, hors de ce dossier.)
--
--  Chaque module correspond à un outil de la chaîne de développement. Le
--  contenu reste générique (titre, statut, échéance, charge utile JSONB) :
--  ce sont des espaces de suivi, pas des intégrations connectées à ces
--  services.
--
--  La navigation du front est construite à partir de cette table : renommer
--  un module ou en ajouter un ne demande aucune modification de code.
-- ============================================================================

BEGIN;

-- Le catalogue précédent était générique (« Module 1 » à « Module 5 »).
-- On le remplace : les slugs changent, les anciennes lignes n'ont plus de
-- correspondance et disparaissent avec leurs éléments (ON DELETE CASCADE).
DELETE FROM modules WHERE slug IN ('module-1', 'module-2', 'module-3', 'module-5');

INSERT INTO modules (slug, name, description, icon, position) VALUES
    (
        'backend',
        'Backend',
        'Base PostgreSQL, authentification, stockage et API temps réel — de quoi prototyper un back complet sans l''écrire.',
        'database',
        10
    ),
    (
        'deploiement',
        'Déploiement',
        'Mises en production liées à Git, avec une URL de prévisualisation par branche. Pas de chaîne d''intégration à maintenir soi-même.',
        'rocket',
        20
    ),
    (
        'tickets',
        'Tickets',
        'Suivi rapide, pensé pour le clavier et les petites équipes. Un statut par projet, là où une liste de contrôle en note libre finit par dériver.',
        'list-check',
        30
    ),
    (
        'supervision',
        'Supervision',
        -- « rejeu de session » a été retiré de cette description : la fonction
        -- n'existe pas et n'est pas prévue. Une promesse qu'on ne tient pas
        -- coûte plus cher que la fonction qu'elle annonce.
        'Erreurs de production groupées par empreinte, avec pile d''appels et compteur d''occurrences : mille fois la même exception reste un seul problème.',
        'bug',
        40
    ),
    (
        'design',
        'Design',
        'Maquettes et prototypes avant d''écrire la moindre ligne — itérer sur un système de composants coûte moins cher en amont.',
        'shapes',
        50
    )
ON CONFLICT (slug) DO UPDATE
    SET name        = EXCLUDED.name,
        description = EXCLUDED.description,
        icon        = EXCLUDED.icon,
        position    = EXCLUDED.position;

COMMIT;
