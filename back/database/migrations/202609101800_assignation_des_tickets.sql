-- ============================================================================
--  À qui revient ce ticket
--
--  ---------------------------------------------------------------------------
--  LA QUESTION N'EXISTAIT PAS AVANT D'ÊTRE PLUSIEURS
--
--  Un suivi personnel n'a pas besoin de dire à qui il revient : tout revient à
--  la même personne. La migration précédente a fait des tickets un bien
--  d'équipe, et laissé un tableau où chacun voit tout sans savoir ce qui
--  l'attend, lui.
--
--  ---------------------------------------------------------------------------
--  « assigned_to » N'EST PAS « created_by »
--
--  Les deux pointent vers un compte, et c'est bien pour cela qu'il faut le
--  dire :
--
--    created_by   — qui l'a ouvert.   Passé, immuable, affiché.
--    assigned_to  — à qui il revient. Présent, mouvant, ce sur quoi on filtre.
--
--  Un ticket ouvert par Alice et confié à Bob est le cas COURANT, pas le cas
--  limite. Confondre les deux colonnes aurait fait disparaître l'un des deux
--  faits à chaque réassignation.
--
--  ---------------------------------------------------------------------------
--  CE QUI RESTE DÉLIBÉRÉMENT DEHORS
--
--  Les quatre autres modules. Tickets est le module-patron : toute mécanique
--  transverse s'y éprouve avant d'être répliquée. « module_items » ne reçoit
--  donc rien ici.
--
--  Un seul assigné, pas une table de liaison. Deux responsables, c'est aucun
--  responsable ; le jour où l'observation contredira cette règle, une table
--  d'assignations pourra la remplacer sans rien perdre.
-- ============================================================================

ALTER TABLE tickets
    ADD COLUMN assigned_to UUID REFERENCES users (id) ON DELETE SET NULL;

COMMENT ON COLUMN tickets.assigned_to IS 'À qui revient ce ticket — NULL signifie « à personne », un état normal';

-- ---------------------------------------------------------------------------
-- L'index du tableau « mes tickets »
-- ---------------------------------------------------------------------------
--
-- Même forme que « tickets_board_idx », dont il est le pendant : l'espace en
-- tête parce qu'il filtre toujours, l'assigné ensuite parce que c'est la
-- question posée, l'ordre du tableau derrière.
--
-- PARTIEL SUR « assigned_to IS NOT NULL » : les tickets non assignés sont
-- nombreux et ne sont jamais cherchés par cette colonne — on les demande par
-- leur absence, ce qu'un index ne sert pas. Les exclure garde l'index petit et
-- utile.
CREATE INDEX tickets_assignee_idx
    ON tickets (organization_id, assigned_to, status, priority DESC, created_at DESC)
 WHERE deleted_at IS NULL AND assigned_to IS NOT NULL;
