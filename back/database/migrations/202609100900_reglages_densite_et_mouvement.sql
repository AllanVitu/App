-- ============================================================================
--  Deux réglages qui agissent réellement
--
--  ---------------------------------------------------------------------------
--  L'ERREUR À NE PAS REFAIRE
--
--  Cet écran a déjà PERDU des réglages : trois interrupteurs de notification
--  et un choix « English » en ont été retirés parce qu'ils étaient enregistrés
--  en base et consommés par personne. Un réglage qui ne change rien est pire
--  que pas de réglage — il fait croire à un contrôle qui n'existe pas.
--
--  Les deux colonnes ajoutées ici sont donc branchées dans le même mouvement
--  que leur ajout, et non « pour plus tard ».
--
--    densite       compact | confortable — l'interface est dense par
--                  construction ; certains veulent plus de lignes à l'écran,
--                  d'autres plus d'air. C'est le seul réglage d'apparence qui
--                  ne se déduit ni du système ni de l'écran.
--
--    reduce_motion l'application ne suivait que « prefers-reduced-motion » du
--                  système. Or on peut vouloir couper les animations d'UNE
--                  application sans les couper partout — et beaucoup de gens
--                  ignorent que ce réglage système existe.
--
--  ---------------------------------------------------------------------------
--  ET UN RÉGLAGE QUI N'EST PAS ICI
--
--  Le SON reste côté client. Il existe déjà, il persiste déjà, et son écran
--  d'entrée est proposé AVANT toute connexion : le stocker par compte le
--  rendrait indisponible au moment précis où il est demandé. Ce qui manquait
--  n'était pas sa persistance mais sa PLACE — il vivait dans la barre du haut
--  sans figurer dans l'écran des préférences. C'est corrigé côté interface.
-- ============================================================================

ALTER TABLE user_settings
    ADD COLUMN density       varchar(12) NOT NULL DEFAULT 'confortable',
    ADD COLUMN reduce_motion boolean     NOT NULL DEFAULT false;

-- La contrainte accompagne la colonne, comme celle du thème juste à côté :
-- une valeur invalide doit être refusée par la BASE, pas seulement par le
-- validateur. Une insertion manuelle en psql produit ainsi une ligne aussi
-- correcte qu'un passage par l'API.
ALTER TABLE user_settings
    ADD CONSTRAINT user_settings_density_valid
    CHECK (density IN ('compact', 'confortable'));

COMMENT ON COLUMN user_settings.density IS 'Densité de l''interface : compact ou confortable';
COMMENT ON COLUMN user_settings.reduce_motion IS 'Coupe les animations, indépendamment du réglage système';
