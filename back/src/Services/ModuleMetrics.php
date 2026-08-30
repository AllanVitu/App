<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TicketRepository;

/**
 * État réel de chaque module.
 *
 * Les modules ne partagent plus une table unique : « tickets » a la sienne,
 * les autres suivront. Le compteur du menu ne peut donc plus se lire dans
 * module_items pour tout le monde — c'est ce qui faisait afficher « tickets 1 »
 * alors que le module en contenait quatorze.
 *
 * Ce service est le SEUL endroit où l'on décide, module par module, ce que
 * son chiffre signifie. Quand le prochain module recevra son propre modèle,
 * il s'ajoutera ici, et le menu comme le tableau de bord suivront sans
 * modification.
 *
 * La sortie est volontairement générique — un nombre, son unité, et les
 * signaux qui demandent attention — pour que le client affiche n'importe
 * quel module sans rien savoir de son métier.
 */
final class ModuleMetrics
{
    private TicketRepository $tickets;

    public function __construct()
    {
        $this->tickets = new TicketRepository();
    }

    /**
     * Enrichit le catalogue avec l'état de chaque module.
     *
     * @param  list<array<string, mixed>> $modules
     * @return list<array<string, mixed>>
     */
    public function decorate(array $modules, string $userId): array
    {
        // Une seule requête pour les tickets, quel que soit le nombre de
        // modules : la calculer dans la boucle la rejouerait inutilement.
        $ticketStats = null;

        foreach ($modules as $index => $module) {
            $state = $module['slug'] === 'tickets'
                ? $this->ticketState($ticketStats ??= $this->tickets->statsForUser($userId))
                : $this->genericState($module);

            // array_merge et non « + » : l'opérateur conserve la valeur de
            // GAUCHE en cas de clé commune, et items_count serait alors
            // corrigé pour tous les modules SAUF ceux qui en avaient besoin.
            $merged = array_merge($module, $state);

            // Chiffre intermédiaire, déjà consommé : le sortir de la réponse
            // évite deux compteurs voisins que le client aurait à départager.
            unset($merged['active_count']);

            $modules[$index] = $merged;
        }

        return $modules;
    }

    /**
     * Un suivi de tickets se juge sur ce qui reste OUVERT : afficher le total
     * ferait grossir le chiffre à chaque ticket terminé, c'est-à-dire à chaque
     * fois que la situation s'améliore.
     *
     * @param  array<string, int> $stats
     * @return array<string, mixed>
     */
    private function ticketState(array $stats): array
    {
        $signals = [];

        if ($stats['urgent'] > 0) {
            $signals[] = $this->signal('urgent', 'urgents', $stats['urgent'], 'alert');
        }

        if ($stats['overdue'] > 0) {
            // « en retard » est invariable : les deux formes sont identiques.
            $signals[] = $this->signal('en retard', 'en retard', $stats['overdue'], 'alert');
        }

        // Rien d'alarmant : on montre l'activité plutôt qu'une tuile vide.
        if ($signals === [] && $stats['in_progress'] > 0) {
            $signals[] = $this->signal('en cours', 'en cours', $stats['in_progress'], 'neutral');
        }

        if ($signals === [] && $stats['done'] > 0) {
            $signals[] = $this->signal('terminé', 'terminés', $stats['done'], 'good');
        }

        return [
            'items_count' => $stats['open'],
            'unit'        => 'ouverts',
            'signals'     => $signals,
        ];
    }

    /**
     * Modules encore adossés à la table générique.
     *
     * @param  array<string, mixed> $module
     * @return array<string, mixed>
     */
    private function genericState(array $module): array
    {
        $total  = (int) ($module['items_count'] ?? 0);
        $active = (int) ($module['active_count'] ?? 0);

        return [
            'items_count' => $total,
            'unit'        => $total === 1 ? 'élément' : 'éléments',
            'signals'     => $active > 0
                ? [$this->signal('actif', 'actifs', $active, 'neutral')]
                : [],
        ];
    }

    /**
     * Signal accordé en nombre.
     *
     * L'accord est fait ICI parce que seul le serveur connaît la valeur au
     * moment où le libellé est choisi. Le laisser au client obligerait chaque
     * affichage — tuile, menu, barre d'état — à refaire la même règle, et
     * « 1 actifs » finirait par ressortir quelque part.
     *
     * @return array{label: string, value: int, tone: string}
     */
    private function signal(string $singular, string $plural, int $value, string $tone): array
    {
        return [
            'label' => $value === 1 ? $singular : $plural,
            'value' => $value,
            'tone'  => $tone,
        ];
    }
}
