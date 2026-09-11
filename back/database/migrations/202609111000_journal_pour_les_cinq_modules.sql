-- ============================================================================
--  Les quatre autres modules rattrapent le premier
--
--  ---------------------------------------------------------------------------
--  UN MODULE SUR CINQ, C'EST LE PIRE DES ÉTATS
--
--  Le jalon précédent a donné aux tickets un journal, un flux temps réel et un
--  arbitrage de conflit. Les quatre autres modules n'ont rien reçu.
--
--  Le résultat est plus trompeur qu'une absence complète : le tableau des
--  tickets bouge tout seul quand un coéquipier travaille, et l'écran des
--  déploiements reste figé. Rien à l'écran n'explique la différence, et
--  personne ne peut deviner laquelle des deux est la règle.
--
--  ---------------------------------------------------------------------------
--  DEUX CHOSES ICI, ET LA SECONDE EST LA PLUS IMPORTANTE
--
--    1. Le jeton de version sur les quatre tables restantes.
--    2. La REPRISE du journal pour tout ce qui existe déjà.
--
--  Sans la reprise, brancher le tableau de bord sur le journal lui ferait
--  afficher un fil vide — alors que l'application contient des données. On
--  aurait remplacé une source qui fonctionnait par une source exacte et
--  inutile, ce qui est une régression même si la requête est plus propre.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Le jeton de version, partout
-- ---------------------------------------------------------------------------
--
-- « bump_version() » existe depuis le jalon précédent et ne suppose rien de la
-- table : il lit OLD.version et pose OLD.version + 1. Il se repose donc tel
-- quel sur les quatre autres.
--
-- error_groups en reçoit un comme les autres, bien qu'on n'y modifie qu'un
-- statut par bouton. La règle qui gouverne l'arbitrage est « le serveur
-- tranche quand le client annonce une version » : une table sans jeton serait
-- une exception à retenir, pour une économie d'un entier par ligne.
ALTER TABLE module_items   ADD COLUMN version INTEGER NOT NULL DEFAULT 1;
ALTER TABLE backend_tables ADD COLUMN version INTEGER NOT NULL DEFAULT 1;
ALTER TABLE deployments    ADD COLUMN version INTEGER NOT NULL DEFAULT 1;
ALTER TABLE error_groups   ADD COLUMN version INTEGER NOT NULL DEFAULT 1;
ALTER TABLE design_files   ADD COLUMN version INTEGER NOT NULL DEFAULT 1;

COMMENT ON COLUMN module_items.version   IS 'Jeton de concurrence, posé par déclencheur — jamais fourni par le client';
COMMENT ON COLUMN backend_tables.version IS 'Jeton de concurrence, posé par déclencheur — jamais fourni par le client';
COMMENT ON COLUMN deployments.version    IS 'Jeton de concurrence, posé par déclencheur — jamais fourni par le client';
COMMENT ON COLUMN error_groups.version   IS 'Jeton de concurrence, posé par déclencheur — jamais fourni par le client';
COMMENT ON COLUMN design_files.version   IS 'Jeton de concurrence, posé par déclencheur — jamais fourni par le client';

CREATE TRIGGER module_items_bump_version
    BEFORE UPDATE ON module_items
    FOR EACH ROW EXECUTE FUNCTION bump_version();

CREATE TRIGGER backend_tables_bump_version
    BEFORE UPDATE ON backend_tables
    FOR EACH ROW EXECUTE FUNCTION bump_version();

CREATE TRIGGER deployments_bump_version
    BEFORE UPDATE ON deployments
    FOR EACH ROW EXECUTE FUNCTION bump_version();

CREATE TRIGGER error_groups_bump_version
    BEFORE UPDATE ON error_groups
    FOR EACH ROW EXECUTE FUNCTION bump_version();

CREATE TRIGGER design_files_bump_version
    BEFORE UPDATE ON design_files
    FOR EACH ROW EXECUTE FUNCTION bump_version();


-- ---------------------------------------------------------------------------
-- 2. La reprise du journal
-- ---------------------------------------------------------------------------
--
-- ┌─────────────────────────────────────────────────────────────────────────┐
-- │  UNE ENTRÉE « created » PAR LIGNE EXISTANTE, ET RIEN DE PLUS            │
-- │                                                                         │
-- │  On ne peut pas reconstituer un historique qui n'a jamais été tenu :    │
-- │  qui a changé quel champ, et quand, n'existe nulle part. Inventer des   │
-- │  modifications plausibles ferait un journal qui MENT, ce qui est pire   │
-- │  qu'un journal qui commence tard.                                       │
-- │                                                                         │
-- │  Ce qui est certain, en revanche : la ligne existe, elle a une date de  │
-- │  création et un auteur. C'est tout ce qui est repris.                   │
-- └─────────────────────────────────────────────────────────────────────────┘
--
-- « happened_at » prend la VRAIE date de création, pas NOW() : le fil du
-- tableau de bord trie dessus, et tout dater d'aujourd'hui ferait remonter en
-- tête des éléments vieux de six mois.
--
-- L'ordre des « id » ne suit donc pas celui des dates. Sans conséquence : le
-- fil trie par happened_at, et le flux temps réel ne regarde que ce qui vient
-- APRÈS son curseur de départ — jamais cette reprise.
--
-- « actor_name » est figé par jointure, comme partout ailleurs dans cette
-- table : le journal doit rester lisible après le départ de son auteur.

INSERT INTO activity (organization_id, actor_id, actor_name, module, action,
                      subject_id, subject_ref, subject_title, subject_version, happened_at)
SELECT t.organization_id, t.created_by, u.full_name, 'tickets', 'created',
       t.id, 'TICK-' || t.number, t.title, t.version, t.created_at
  FROM tickets t
  LEFT JOIN users u ON u.id = t.created_by;

INSERT INTO activity (organization_id, actor_id, actor_name, module, action,
                      subject_id, subject_ref, subject_title, subject_version, happened_at)
SELECT i.organization_id, i.created_by, u.full_name, m.slug, 'created',
       i.id, NULL, i.title, i.version, i.created_at
  FROM module_items i
  JOIN modules m ON m.id = i.module_id
  LEFT JOIN users u ON u.id = i.created_by;

INSERT INTO activity (organization_id, actor_id, actor_name, module, action,
                      subject_id, subject_ref, subject_title, subject_version, happened_at)
SELECT b.organization_id, b.created_by, u.full_name, 'backend', 'created',
       b.id, NULL, b.name, b.version, b.created_at
  FROM backend_tables b
  LEFT JOIN users u ON u.id = b.created_by;

-- La référence d'un déploiement est « branche@empreinte », comme à l'écran :
-- c'est ce qu'on cherche du regard dans un fil.
INSERT INTO activity (organization_id, actor_id, actor_name, module, action,
                      subject_id, subject_ref, subject_title, subject_version, happened_at)
SELECT d.organization_id, d.created_by, u.full_name, 'deploiement', 'created',
       d.id, d.branch || '@' || left(d.commit_sha, 7), d.commit_message, d.version, d.created_at
  FROM deployments d
  LEFT JOIN users u ON u.id = d.created_by;

-- Un groupe d'erreurs n'a PAS d'auteur : il naît de la première occurrence
-- reçue, souvent d'une clé de service. « actor_name » reste donc nul, et le
-- fil l'affichera sans nom plutôt qu'avec un nom emprunté.
INSERT INTO activity (organization_id, actor_id, actor_name, module, action,
                      subject_id, subject_ref, subject_title, subject_version, happened_at)
SELECT g.organization_id, NULL, NULL, 'supervision', 'created',
       g.id, NULL, g.title, g.version, g.created_at
  FROM error_groups g;

INSERT INTO activity (organization_id, actor_id, actor_name, module, action,
                      subject_id, subject_ref, subject_title, subject_version, happened_at)
SELECT f.organization_id, f.created_by, u.full_name, 'design', 'created',
       f.id, NULL, f.name, f.version, f.created_at
  FROM design_files f
  LEFT JOIN users u ON u.id = f.created_by;
