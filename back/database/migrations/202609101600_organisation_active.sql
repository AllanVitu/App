-- ============================================================================
--  L'organisation que le compte regarde en ce moment
--
--  ---------------------------------------------------------------------------
--  POURQUOI EN BASE ET NON DANS LE JETON
--
--  Mettre l'organisation active dans le JWT aurait été tentant : aucun accès
--  supplémentaire, et un changement d'espace par simple ré-émission.
--
--  Mais un jeton vit quinze minutes, et pendant ces quinze minutes il affirme
--  une appartenance qui peut avoir été RÉVOQUÉE. AuthMiddleware recharge déjà
--  le compte depuis la base à chaque requête pour cette raison exacte — « un
--  compte désactivé perd immédiatement l'accès, sans attendre l'expiration de
--  son jeton ». L'appartenance obéit à la même règle, et suit donc le même
--  chemin.
--
--  ---------------------------------------------------------------------------
--  CE QUE CELA IMPLIQUE, ET QU'IL FAUT ASSUMER
--
--  L'espace actif appartient au COMPTE, pas à la session : changer d'espace
--  sur un poste le change sur tous. C'est prévisible, faute d'être subtil —
--  la seule façon d'avoir deux espaces ouverts côte à côte serait de porter le
--  slug dans l'URL, ce qui est un autre chantier.
--
--  ON DELETE SET NULL : la suppression d'une organisation ne doit pas emporter
--  les comptes qui la regardaient. Le repli sur la plus ancienne appartenance
--  est fait à la lecture, par OrganizationRepository::activeFor().
-- ============================================================================

ALTER TABLE users
    ADD COLUMN active_organization_id UUID REFERENCES organizations (id) ON DELETE SET NULL;

COMMENT ON COLUMN users.active_organization_id IS 'Espace de travail affiché — NULL signifie « la plus ancienne appartenance »';

-- Chaque compte existant regarde la sienne, la seule qu'il ait.
UPDATE users u
   SET active_organization_id = m.organization_id
  FROM memberships m
 WHERE m.user_id = u.id;
