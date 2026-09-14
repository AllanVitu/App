-- ============================================================================
--  Les modules se parlent : commentaires, erreur → ticket
--
--  ---------------------------------------------------------------------------
--  UNE DISCUSSION À CÔTÉ DU TICKET, PAS DANS SA DESCRIPTION
--
--  Sans commentaires, une équipe discute d'un ticket en réécrivant sa
--  description — et le conflit de version (cf. Journal::assertNoConflict) le
--  lui refuse à juste titre, puisque deux personnes écrivent le même champ.
--  Un commentaire est une ligne à lui : deux personnes qui répondent en même
--  temps n'entrent jamais en conflit.
--
--  Le texte est stocké tel qu'il a été écrit et affiché comme du TEXTE, par le
--  même découpage que la Documentation (cf. front/src/utils/richText.js) :
--  jamais de HTML.
--
--  ---------------------------------------------------------------------------
--  UNE ERREUR DEVIENT UN TICKET, ET S'EN SOUVIENT
--
--  « Qui s'en occupe ? » est la question qu'une erreur de production pose, et
--  Supervision n'avait pas de réponse. Le ticket créé depuis une erreur y reste
--  attaché : l'erreur montre son numéro et son statut, et un second clic ne
--  crée pas un doublon.
--
--  ON DELETE SET NULL : supprimer DÉFINITIVEMENT le ticket rend l'erreur à
--  « personne », il ne l'emporte pas avec lui.
-- ============================================================================


CREATE TABLE ticket_comments (
    id              UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id UUID          NOT NULL REFERENCES organizations (id) ON DELETE CASCADE,
    ticket_id       UUID          NOT NULL REFERENCES tickets (id) ON DELETE CASCADE,

    -- SET NULL : le commentaire d'un compte supprimé reste lisible, sans nom.
    author_id       UUID          REFERENCES users (id) ON DELETE SET NULL,

    body            TEXT          NOT NULL,

    created_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    deleted_at      TIMESTAMPTZ,

    CONSTRAINT ticket_comments_body_len CHECK (char_length(btrim(body)) BETWEEN 1 AND 5000)
);

COMMENT ON TABLE ticket_comments IS 'Discussion d''un ticket ; le texte est du texte, jamais du HTML';

-- Le fil d'un ticket se lit dans l'ordre : c'est la seule lecture qui existe.
CREATE INDEX ticket_comments_thread_idx
    ON ticket_comments (ticket_id, created_at)
 WHERE deleted_at IS NULL;


ALTER TABLE error_groups
    ADD COLUMN ticket_id UUID REFERENCES tickets (id) ON DELETE SET NULL;

COMMENT ON COLUMN error_groups.ticket_id IS 'Ticket ouvert depuis cette erreur — NULL tant que personne ne s''en est chargé';

CREATE INDEX error_groups_ticket_idx
    ON error_groups (ticket_id)
 WHERE ticket_id IS NOT NULL;
