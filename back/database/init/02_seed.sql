-- ============================================================================
--  SaaS Starter — Données de démarrage
--
--  Joué juste après 01_schema.sql, à la création du volume PostgreSQL.
--  Contient le strict nécessaire au fonctionnement de l'application :
--  le catalogue des modules. (Le compte de démonstration est dans 03_seed_dev.sql.)
-- ============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- Catalogue des modules
--
-- Le numéro 4 est volontairement absent : la navigation du front est
-- construite à partir de cette table, pas d'une boucle sur 1..n.
-- Renommer un module = un simple UPDATE, sans toucher au code.
-- ---------------------------------------------------------------------------
INSERT INTO modules (slug, name, description, icon, position) VALUES
    ('module-1', 'Module 1', 'Premier module métier — à personnaliser.',   'layout-grid', 10),
    ('module-2', 'Module 2', 'Deuxième module métier — à personnaliser.',  'chart-bar',   20),
    ('module-3', 'Module 3', 'Troisième module métier — à personnaliser.', 'folder',      30),
    ('module-5', 'Module 5', 'Cinquième module métier — à personnaliser.', 'sparkles',    50)
ON CONFLICT (slug) DO NOTHING;

COMMIT;
