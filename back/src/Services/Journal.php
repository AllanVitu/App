<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Request;
use App\Models\ActivityRepository;

/**
 * Ce qu'un module consigne, et ce qu'il refuse.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  UN MODULE SUR CINQ, C'ÉTAIT LE PIRE DES ÉTATS                          │
 * │                                                                         │
 * │  Le jalon précédent a donné aux tickets un journal, un flux temps réel  │
 * │  et un arbitrage de conflit — écrits À MÊME le contrôleur. Les quatre   │
 * │  autres modules n'avaient rien, et le tableau des tickets bougeait seul │
 * │  pendant que l'écran des déploiements restait figé, sans que rien       │
 * │  n'explique la différence.                                              │
 * │                                                                         │
 * │  Recopier cent cinquante lignes quatre fois aurait donné cinq variantes │
 * │  qui divergeraient à la première correction. Cette classe est ce qui    │
 * │  restait à en extraire : elle ne sait rien d'un ticket, seulement d'un  │
 * │  MODULE, de ses CHAMPS SUIVIS, et de la requête en cours.               │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Elle réunit deux services qui n'en font qu'un : ce qui est consigné est
 * exactement ce qui sert à arbitrer. Les séparer aurait permis à un champ
 * d'être arbitré sans être journalisé — donc d'être refusé sans qu'on puisse
 * dire par qui.
 */
final class Journal
{
    private ActivityRepository $activity;

    /**
     * @param string                $module Le slug du module, tel qu'il apparaît dans le fil
     * @param array<string, string> $fields Champs suivis : nom technique => libellé français
     */
    public function __construct(
        private readonly string $module,
        private readonly array $fields,
    ) {
        $this->activity = new ActivityRepository();
    }

    // -----------------------------------------------------------------------
    //  Consigner
    // -----------------------------------------------------------------------

    /**
     * Consigne un fait.
     *
     * « ref » et « titre » sont FIGÉS au moment du fait : après une
     * suppression, ils ne sont plus lisibles ailleurs, et le fil afficherait
     * une ligne muette.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     */
    public function record(
        Request $request,
        string $action,
        string $subjectId,
        ?string $ref = null,
        ?string $title = null,
        array $changes = [],
        ?int $version = null,
    ): void {
        $this->activity->record(
            $request->organizationId(),
            $request->actorId(),
            $this->actorName($request),
            $this->module,
            $action,
            $subjectId,
            $ref,
            $title,
            $changes,
            $version,
        );
    }

    /**
     * Ce qui a RÉELLEMENT changé — { champ: [avant, après] }.
     *
     * Comparé après écriture plutôt que déduit du corps reçu : renvoyer le
     * même titre qu'avant n'est pas un changement, et un journal qui le
     * consignerait ferait du bruit dans le fil de toute l'équipe.
     *
     * @param  array<string, mixed> $avant
     * @param  array<string, mixed> $apres
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function diff(array $avant, array $apres): array
    {
        $changes = [];

        foreach (array_keys($this->fields) as $champ) {
            if (($avant[$champ] ?? null) !== ($apres[$champ] ?? null)) {
                $changes[$champ] = [$avant[$champ] ?? null, $apres[$champ] ?? null];
            }
        }

        return $changes;
    }

    // -----------------------------------------------------------------------
    //  Arbitrer
    // -----------------------------------------------------------------------

    /**
     * ┌───────────────────────────────────────────────────────────────────────┐
     * │  LA DERNIÈRE ÉCRITURE GAGNAIT, EN SILENCE                            │
     * │                                                                       │
     * │  Alice et Bob ouvrent le même élément. Alice écrit une description,   │
     * │  Bob change le statut. Celui qui enregistre en second écrasait le     │
     * │  travail de l'autre, sans que personne ne l'apprenne jamais.          │
     * │                                                                       │
     * │  DEUX IDÉES, ET LA SECONDE COMPTE PLUS QUE LA PREMIÈRE.               │
     * │                                                                       │
     * │  1. Un jeton de version. Le client renvoie celui qu'il détient ; s'il │
     * │     ne correspond plus, sa base de départ est périmée.                │
     * │                                                                       │
     * │  2. UNE VERSION PÉRIMÉE N'EST PAS UN CONFLIT. C'est le point. Neuf    │
     * │     fois sur dix, l'autre a touché un champ que je ne touche pas, et  │
     * │     refuser l'écriture ferait perdre un paragraphe pour rien.         │
     * │     Le journal dit quels champs ont bougé ; seule l'intersection      │
     * │     avec ceux que j'écris est un vrai désaccord.                      │
     * └───────────────────────────────────────────────────────────────────────┘
     *
     * LE SERVEUR ARBITRE EXACTEMENT QUAND LE CLIENT ANNONCE UNE VERSION.
     * C'est la règle entière, et elle vaut pour les cinq modules. Un raccourci
     * clavier écrit un champ unique et instantané : lui imposer un
     * aller-retour de lecture d'abord annulerait ce qui fait l'intérêt du
     * module, et il n'annonce donc rien.
     *
     * @param array<string, mixed> $existing La ligne telle qu'elle est en base
     */
    public function assertNoConflict(Request $request, array $existing, string $sujet): void
    {
        $connue = $request->all()['version'] ?? null;

        if (!is_int($connue) && !is_string($connue)) {
            return;
        }

        $connue = (int) $connue;

        if ($connue >= (int) ($existing['version'] ?? 0)) {
            return;
        }

        // Ce que d'AUTRES ont touché depuis. Mes propres écritures sont
        // exclues : deux onglets à moi se marchent dessus, mais me demander
        // d'arbitrer contre moi-même n'aiderait personne.
        $bouges = $this->activity->changedSince(
            (string) $existing['id'],
            $connue,
            $request->actorId(),
        );

        $disputes = array_intersect_key($bouges, array_flip($this->intendedFields($request)));

        if ($disputes === []) {
            return;
        }

        $messages = [];

        foreach ($disputes as $champ => $auteur) {
            $messages[$champ] = sprintf(
                '%s a modifié « %s » pendant que vous éditiez.',
                $auteur,
                $this->fields[$champ] ?? $champ,
            );
        }

        throw new HttpException(
            409,
            sprintf('%s a changé pendant que vous l\'éditiez.', $sujet),
            $messages,
            null,
            // L'état courant part AVEC le refus : sans lui, le client ne
            // pourrait que recharger, donc perdre ce qui était en cours.
            ['current' => $existing],
        );
    }

    // -----------------------------------------------------------------------

    /**
     * Les champs que cette requête entend écrire.
     *
     * Lus dans le CORPS et non déduits du résultat : une mise à jour partielle
     * ne mentionne que ce qu'elle change, et c'est exactement la liste dont
     * l'arbitrage a besoin.
     *
     * @return list<string>
     */
    private function intendedFields(Request $request): array
    {
        return array_values(array_filter(
            array_keys($this->fields),
            static fn (string $champ): bool => $request->has($champ),
        ));
    }

    /**
     * Le nom de l'auteur, figé dans le journal.
     *
     * Null pour une clé de service : elle n'est personne, et lui prêter un nom
     * emprunté rendrait le fil faux là où il doit être une preuve.
     */
    private function actorName(Request $request): ?string
    {
        $user = $request->attribute('user');

        return is_array($user) ? (string) $user['full_name'] : null;
    }
}
