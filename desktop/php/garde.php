<?php

declare(strict_types=1);

/**
 * ===========================================================================
 *  La garde du point d'entrée — jouée avant tout script (auto_prepend_file)
 *
 *  ---------------------------------------------------------------------------
 *  CE QU'ELLE EMPÊCHE
 *
 *  Sur le poste, php-cgi écoute en FastCGI sur 127.0.0.1. Une adresse de
 *  bouclage n'est pas un cloisonnement : tout programme de la machine peut s'y
 *  connecter, y compris celui d'un AUTRE compte Windows. Et en FastCGI, c'est
 *  le client qui désigne le script à exécuter (SCRIPT_FILENAME) — un fichier
 *  .php déposé dans C:\Users\Public serait alors exécuté sous l'identité de la
 *  personne qui utilise Relais, avec ses secrets et sa base.
 *
 *  Deux conditions, vérifiées ici avant la moindre ligne de l'application :
 *
 *   1. la requête porte le jeton tiré au lancement. Il vit dans le php.ini de
 *      la session, écrit dans le dossier de données du compte — qu'un autre
 *      compte ne peut pas lire — et n'est lisible qu'avec get_cfg_var() :
 *      aucun paramètre FastCGI ne peut le remplacer ;
 *   2. le script demandé est le point d'entrée de l'API, et aucun autre.
 *
 *  Le jeton n'est pas un paramètre que PHP relit ensuite : dès la
 *  vérification faite, il disparaît de $_SERVER, pour ne jamais figurer dans
 *  une trace ou un vidage de variables.
 *
 *  Le fichier .user.ini, qui permettrait à un dossier de désactiver cette
 *  garde, est lui-même désactivé dans le php.ini (user_ini.filename vide).
 * ===========================================================================
 */

if (PHP_SAPI === 'cli') {
    // Le worker et l'installation de la base sont lancés par l'application,
    // en ligne de commande : ils ne reçoivent aucune requête.
    return;
}

(static function (): void {
    $attendu = get_cfg_var('relais.jeton');
    $entree  = get_cfg_var('relais.point_entree');
    $recu    = $_SERVER['RELAIS_JETON'] ?? null;

    unset($_SERVER['RELAIS_JETON']);

    $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $cible  = is_string($entree) ? realpath($entree) : false;

    $jetonValide = is_string($attendu)
        && strlen($attendu) >= 64
        && is_string($recu)
        && hash_equals($attendu, $recu);

    // Sous Windows, deux écritures d'un même chemin peuvent différer par la
    // casse : la comparaison l'ignore, comme le système de fichiers.
    $scriptValide = $script !== false
        && $cible !== false
        && strcasecmp($script, $cible) === 0;

    if (!$jetonValide || !$scriptValide) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');

        exit;
    }
})();
