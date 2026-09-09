<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\RefreshTokenService;

/**
 * Sessions ouvertes de l'utilisateur, et leur fermeture à distance.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  POURQUOI CES ROUTES SONT SOUS « /api/auth » ET NON SOUS « /api/profile »│
 * │                                                                         │
 * │  L'écran qui les consomme est le profil, mais le cookie de              │
 * │  rafraîchissement est déposé avec « path=/api/auth » — restriction      │
 * │  délibérée, qui limite son envoi aux seules routes d'authentification.  │
 * │                                                                         │
 * │  Or c'est ce cookie, et lui seul, qui permet de reconnaître la session  │
 * │  COURANTE parmi les autres. Sous « /api/profile », le navigateur ne     │
 * │  l'enverrait pas : impossible alors de marquer « cet appareil », ni     │
 * │  d'épargner l'appelant quand il ferme les autres.                       │
 * │                                                                         │
 * │  L'alternative — élargir le chemin du cookie — reviendrait à affaiblir  │
 * │  une protection pour la commodité d'une URL. Le rangement suit donc la  │
 * │  contrainte réelle : ces routes appartiennent au domaine de la session. │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Le jeton en clair ne quitte jamais le serveur, et son empreinte non plus :
 * la réponse ne porte qu'un identifiant de ligne, de quoi révoquer.
 */
final class SessionController
{
    private RefreshTokenService $refreshTokens;

    public function __construct()
    {
        $this->refreshTokens = new RefreshTokenService();
    }

    /**
     * GET /api/auth/sessions
     */
    public function index(Request $request): void
    {
        $sessions = $this->refreshTokens->activeSessions(
            $request->userId(),
            $request->cookie(RefreshTokenService::COOKIE_NAME),
        );

        Response::json(array_map([$this, 'present'], $sessions));
    }

    /**
     * DELETE /api/auth/sessions/{id}
     */
    public function destroy(Request $request): void
    {
        $id = $request->param('id');

        if ($id === null || preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
            throw HttpException::notFound('Session introuvable.');
        }

        if (!$this->refreshTokens->revokeById($id, $request->userId())) {
            // Même réponse pour « n'existe pas » et « appartient à un autre
            // compte » : distinguer les deux dirait à un attaquant quels
            // identifiants sont réels.
            throw HttpException::notFound('Session introuvable.');
        }

        Response::noContent();
    }

    /**
     * DELETE /api/auth/sessions — toutes sauf celle-ci.
     */
    public function destroyOthers(Request $request): void
    {
        $closed = $this->refreshTokens->revokeOthers(
            $request->userId(),
            $request->cookie(RefreshTokenService::COOKIE_NAME),
        );

        Response::json(['closed' => $closed]);
    }

    /**
     * Met une session en forme pour l'écran.
     *
     * L'agent utilisateur brut est conservé : lui seul permet à quelqu'un de
     * reconnaître une machine précise quand deux appareils portent le même
     * libellé. Le libellé lisible l'accompagne, il ne le remplace pas.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function present(array $session): array
    {
        return [
            'id'         => $session['id'],
            'label'      => self::describe(is_string($session['user_agent']) ? $session['user_agent'] : null),
            'user_agent' => $session['user_agent'],
            'ip_address' => $session['ip_address'],
            'created_at' => $session['created_at'],
            'expires_at' => $session['expires_at'],
            'current'    => $session['current'],
        ];
    }

    /**
     * Libellé lisible d'un agent utilisateur.
     *
     * VOLONTAIREMENT COURT ET FAILLIBLE. Reconnaître un navigateur à sa
     * signature est un problème sans fond : les chaînes mentent par
     * construction — Chrome se déclare Safari, qui se déclare Mozilla — et
     * les bibliothèques qui s'y attaquent pèsent plus que ce module entier.
     *
     * Ici l'enjeu est modeste : aider quelqu'un à reconnaître SES appareils
     * dans une liste qui en compte deux ou trois. Se tromper de version de
     * navigateur n'a aucune conséquence ; l'agent brut reste affiché à côté
     * pour trancher. D'où l'ordre des motifs, qui compte : Edge et Opera se
     * présentent comme Chrome, Chrome se présente comme Safari.
     */
    private static function describe(?string $agent): string
    {
        if ($agent === null || trim($agent) === '') {
            return 'Appareil inconnu';
        }

        $navigateurs = [
            'Edg/'     => 'Edge',
            'OPR/'     => 'Opera',
            'Firefox/' => 'Firefox',
            'Chrome/'  => 'Chrome',
            'Safari/'  => 'Safari',
        ];

        $systemes = [
            'Windows NT' => 'Windows',
            'Android'    => 'Android',
            'iPhone'     => 'iPhone',
            'iPad'       => 'iPad',
            'Mac OS X'   => 'macOS',
            'Linux'      => 'Linux',
        ];

        $navigateur = self::premier($agent, $navigateurs);
        $systeme    = self::premier($agent, $systemes);

        if ($navigateur === null && $systeme === null) {
            return 'Appareil inconnu';
        }

        if ($navigateur === null) {
            return (string) $systeme;
        }

        return $systeme === null ? $navigateur : "{$navigateur} sur {$systeme}";
    }

    /**
     * Premier motif reconnu, dans l'ordre de déclaration.
     *
     * @param array<string, string> $motifs
     */
    private static function premier(string $agent, array $motifs): ?string
    {
        foreach ($motifs as $motif => $nom) {
            if (str_contains($agent, $motif)) {
                return $nom;
            }
        }

        return null;
    }
}
