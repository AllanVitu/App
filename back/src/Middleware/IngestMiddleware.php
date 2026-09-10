<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Models\BackendRepository;
use App\Models\OrganizationRepository;
use App\Models\UserRepository;
use App\Services\Jwt;

/**
 * Protège les routes d'INGESTION, qui acceptent deux natures d'appelant.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  POURQUOI CE MIDDLEWARE EXISTE                                      │
 * │                                                                     │
 * │  Le module Supervision promet des « erreurs de production ». Il      │
 * │  n'avait aucune porte d'entrée : POST /api/errors était protégé par │
 * │  AuthMiddleware, donc réservé à un jeton de session — valable quinze │
 * │  minutes et détenu par un navigateur connecté.                      │
 * │                                                                     │
 * │  Autrement dit : l'application supervisée aurait dû se connecter au │
 * │  compte de son propriétaire pour signaler une erreur. Personne ne    │
 * │  peut faire ça. Le module ne recevait donc jamais rien de réel, et   │
 * │  ne supervisait que son propre jeu de démonstration.                 │
 * │                                                                     │
 * │  Une clé d'API, elle, est faite pour ça : longue durée, révocable,   │
 * │  détenue par un serveur. Le module Backend en créait déjà — elles    │
 * │  n'ouvraient simplement rien.                                        │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * DEUX APPELANTS, UN SEUL RÉSULTAT. Que l'appel vienne d'une session ou d'une
 * clé, l'attribut « organization » est posé de la même façon : les contrôleurs
 * d'ingestion n'ont donc rien à savoir de tout ceci, et appellent
 * `$request->organizationId()` comme les autres.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  CE QUI DIFFÈRE : L'ATTRIBUT « user » PEUT ÊTRE ABSENT                  │
 * │                                                                         │
 * │  Une clé de service désigne un ESPACE, pas une personne. Il y a donc un │
 * │  destinataire mais aucun auteur, et c'est ce que dit `actorId()` en     │
 * │  renvoyant null — là où `userId()` refuserait la requête.               │
 * │                                                                         │
 * │  C'est aussi la bonne réponse métier : une intégration de production    │
 * │  doit continuer d'émettre le jour où celui qui a créé la clé quitte     │
 * │  l'équipe.                                                              │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * L'ORDRE DE RECONNAISSANCE EST DÉTERMINÉ PAR LE PRÉFIXE, jamais par un essai
 * suivi d'un repli. Tenter la vérification JWT sur une clé d'API produirait un
 * message d'erreur trompeur — « jeton expiré » sur une clé parfaitement
 * valide — et masquerait la vraie cause pendant des heures.
 */
final class IngestMiddleware
{
    /**
     * Préfixes posés par BackendRepository::createKey().
     *
     * « pk_ » est reconnu ici uniquement pour pouvoir le REFUSER avec un
     * message juste : une clé publique est destinée au navigateur, elle n'a
     * rien à faire en écriture. Sans ce cas, elle tomberait dans la branche
     * JWT et l'utilisateur lirait « jeton illisible ».
     */
    private const KEY_PREFIXES = ['sk_', 'pk_'];

    public function handle(Request $request): void
    {
        $token = $request->bearerToken();

        if ($token === null) {
            throw HttpException::unauthorized(
                'Authentification requise : une clé d\'API de service, ou une session ouverte.',
            );
        }

        $isApiKey = false;

        foreach (self::KEY_PREFIXES as $prefix) {
            if (str_starts_with($token, $prefix)) {
                $isApiKey = true;

                break;
            }
        }

        $isApiKey ? $this->fromApiKey($request, $token) : $this->fromSession($request, $token);
    }

    /**
     * Une clé de service : elle pose l'organisation, et RIEN d'autre. Aucun
     * attribut « user », donc aucun auteur — ce qui est exact.
     */
    private function fromApiKey(Request $request, string $token): void
    {
        $key = (new BackendRepository())->findOrganizationByKey($token);

        // Un seul message pour « inconnue », « révoquée » et « publique » :
        // distinguer les trois indiquerait à un attaquant qu'une clé a existé.
        if ($key === null) {
            throw HttpException::unauthorized('Clé d\'API inconnue, révoquée, ou sans droit d\'écriture.');
        }

        $organization = (new OrganizationRepository())->findById($key['organization_id']);

        if ($organization === null) {
            throw HttpException::unauthorized('L\'espace de travail lié à cette clé est introuvable.');
        }

        // Une clé n'a pas de rôle : elle n'ouvre que l'ingestion, et aucune
        // route d'ingestion n'en demande. Lui en prêter un ouvrirait, le jour
        // où une route protégée passerait par ici, des droits que personne
        // n'a accordés.
        $request->setAttribute('organization', $organization + ['role' => 'member']);
        $request->setAttribute('api_key', $key);
    }

    /**
     * Une session ouverte : le compte ET son organisation, exactement comme
     * dans AuthMiddleware. Le compte est rechargé depuis la base — un compte
     * supprimé ou désactivé perd l'accès immédiatement, sans attendre qu'on
     * pense à révoquer ses clés une par une.
     */
    private function fromSession(Request $request, string $token): void
    {
        $claims = Jwt::verify($token);

        $user = (new UserRepository())->findById((string) $claims['sub']);

        if ($user === null) {
            throw HttpException::unauthorized('Compte introuvable.');
        }

        if (!$user['is_active']) {
            throw HttpException::forbidden('Ce compte est désactivé.');
        }

        $organization = (new OrganizationRepository())->activeFor(
            $user['id'],
            $user['active_organization_id'],
        );

        if ($organization === null) {
            throw HttpException::forbidden('Ce compte n\'appartient à aucun espace de travail.');
        }

        $request->setAttribute('user', $user);
        $request->setAttribute('organization', $organization);
    }
}
