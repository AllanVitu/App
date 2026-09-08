<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Models\BackendRepository;
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
 * clé, l'attribut « user » est posé de la même façon : les contrôleurs
 * d'ingestion n'ont donc rien à savoir de tout ceci, et continuent d'appeler
 * `$request->userId()`.
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

        $user = $isApiKey ? $this->fromApiKey($token) : $this->fromSession($token);

        $request->setAttribute('user', $user);
    }

    /**
     * @return array<string, mixed>
     */
    private function fromApiKey(string $token): array
    {
        $key = (new BackendRepository())->findUserByKey($token);

        // Un seul message pour « inconnue », « révoquée » et « publique » :
        // distinguer les trois indiquerait à un attaquant qu'une clé a existé.
        if ($key === null) {
            throw HttpException::unauthorized('Clé d\'API inconnue, révoquée, ou sans droit d\'écriture.');
        }

        return $this->activeUser($key['user_id'], 'Le compte lié à cette clé est introuvable.');
    }

    /**
     * @return array<string, mixed>
     */
    private function fromSession(string $token): array
    {
        $claims = Jwt::verify($token);

        return $this->activeUser((string) $claims['sub'], 'Compte introuvable.');
    }

    /**
     * Le compte est rechargé depuis la base, comme dans AuthMiddleware : un
     * compte supprimé ou désactivé perd l'accès immédiatement, sans attendre
     * qu'on pense à révoquer ses clés une par une.
     *
     * @return array<string, mixed>
     */
    private function activeUser(string $userId, string $missing): array
    {
        $user = (new UserRepository())->findById($userId);

        if ($user === null) {
            throw HttpException::unauthorized($missing);
        }

        if (!$user['is_active']) {
            throw HttpException::forbidden('Ce compte est désactivé.');
        }

        return $user;
    }
}
