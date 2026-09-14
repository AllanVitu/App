<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Conditions générales d'utilisation.
 *
 * La version est déclarée ici et enregistrée avec chaque acceptation.
 * Sans elle, la trace en base dirait seulement que l'utilisateur a accepté
 * « quelque chose » — ce qui ne prouve rien le jour où les conditions
 * changent.
 *
 * En faire évoluer le numéro suffit à redemander le consentement : les
 * comptes dont la version enregistrée diffère sont considérés comme
 * n'ayant pas accepté la version courante.
 */
final class Terms
{
    public const CURRENT_VERSION = '1.1';

    /** Date d'entrée en vigueur, affichée sur la page des conditions. */
    public const EFFECTIVE_DATE = '2026-09-14';
}
