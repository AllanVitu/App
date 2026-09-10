<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BackendRepository;
use App\Models\DeploymentRepository;
use App\Models\DesignRepository;
use App\Models\ErrorRepository;
use App\Models\TicketRepository;

/**
 * État réel de chaque module.
 *
 * Les modules ne partagent plus une table unique : chacun a la sienne. Le
 * compteur du menu ne peut donc pas se lire dans module_items pour tout le
 * monde — c'est ce qui faisait afficher « tickets 1 » alors que le module en
 * contenait quatorze.
 *
 * Ce service est le SEUL endroit où l'on décide, module par module, ce que
 * son chiffre signifie. Le menu, le tableau de bord et la barre d'état en
 * découlent sans rien savoir du métier de chacun.
 *
 * Le chiffre mis en avant est toujours celui du TRAVAIL RESTANT, jamais un
 * total cumulé : afficher le total ferait grossir le compteur à chaque tâche
 * terminée, c'est-à-dire chaque fois que la situation s'améliore.
 *
 * La sortie est volontairement générique — un nombre, son unité, et les
 * signaux qui demandent attention — pour que le client affiche n'importe
 * quel module sans rien savoir de son métier.
 */
final class ModuleMetrics
{
    /**
     * Indicateurs déjà calculés pendant cette requête.
     *
     * Le catalogue est parcouru une fois, mais mieux vaut ne pas dépendre de
     * l'ordre : la mémoïsation garantit une requête par module, quel que soit
     * le nombre d'appels.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $cache = [];

    /**
     * Enrichit le catalogue avec l'état de chaque module.
     *
     * @param  list<array<string, mixed>> $modules
     * @return list<array<string, mixed>>
     */
    public function decorate(array $modules, string $organizationId): array
    {
        foreach ($modules as $index => $module) {
            $state = match ($module['slug']) {
                'tickets'     => $this->ticketState($organizationId),
                'backend'     => $this->backendState($organizationId),
                'deploiement' => $this->deploymentState($organizationId),
                'supervision' => $this->errorState($organizationId),
                'design'      => $this->designState($organizationId),
                // Module ajouté en base sans code dédié : il reste adossé à la
                // table générique, et le catalogue continue de fonctionner.
                default       => $this->genericState($module),
            };

            // array_merge et non « + » : l'opérateur conserve la valeur de
            // GAUCHE en cas de clé commune, et items_count ne serait alors
            // jamais corrigé.
            $merged = array_merge($module, $state);

            // Chiffre intermédiaire, déjà consommé : le sortir de la réponse
            // évite deux compteurs voisins que le client aurait à départager.
            unset($merged['active_count']);

            $modules[$index] = $merged;
        }

        return $modules;
    }

    /**
     * @param  callable(): array<string, mixed> $compute
     * @return array<string, mixed>
     */
    private function stats(string $key, string $organizationId, callable $compute): array
    {
        return $this->cache[$key . ':' . $organizationId] ??= $compute();
    }

    /**
     * Tickets : ce qui reste OUVERT.
     *
     * @return array<string, mixed>
     */
    private function ticketState(string $organizationId): array
    {
        $stats = $this->stats('tickets', $organizationId, fn (): array => (new TicketRepository())->statsForOrganization($organizationId));

        $signals = [];

        if ($stats['urgent'] > 0) {
            $signals[] = $this->signal('urgent', 'urgents', $stats['urgent'], 'alert');
        }

        if ($stats['overdue'] > 0) {
            $signals[] = $this->signal('en retard', 'en retard', $stats['overdue'], 'alert');
        }

        if ($signals === [] && $stats['in_progress'] > 0) {
            $signals[] = $this->signal('en cours', 'en cours', $stats['in_progress'], 'neutral');
        }

        if ($signals === [] && $stats['done'] > 0) {
            $signals[] = $this->signal('terminé', 'terminés', $stats['done'], 'good');
        }

        return $this->state($stats['open'], 'ouvert', 'ouverts', $signals);
    }

    /**
     * Backend : le nombre de schémas conçus.
     *
     * L'alerte porte sur les tables sans sécurité au niveau ligne — le seul
     * chiffre du module qui décrive un risque plutôt qu'un volume.
     *
     * @return array<string, mixed>
     */
    private function backendState(string $organizationId): array
    {
        $stats = $this->stats('backend', $organizationId, fn (): array => (new BackendRepository())->statsForOrganization($organizationId));

        $signals = [];

        if ($stats['unprotected'] > 0) {
            $signals[] = $this->signal('sans RLS', 'sans RLS', $stats['unprotected'], 'alert');
        }

        if ($stats['active_keys'] > 0) {
            $signals[] = $this->signal('clé active', 'clés actives', $stats['active_keys'], 'neutral');
        }

        return $this->state($stats['tables'], 'table', 'tables', $signals);
    }

    /**
     * Déploiement : le volume déployé, avec les échecs en alerte.
     *
     * @return array<string, mixed>
     */
    private function deploymentState(string $organizationId): array
    {
        $stats = $this->stats(
            'deploiement',
            $organizationId,
            fn (): array => (new DeploymentRepository())->statsForOrganization($organizationId),
        );

        $signals = [];

        if ($stats['failed'] > 0) {
            $signals[] = $this->signal('en échec', 'en échec', $stats['failed'], 'alert');
        }

        if ($stats['running'] > 0) {
            $signals[] = $this->signal('en cours', 'en cours', $stats['running'], 'neutral');
        }

        if ($signals === [] && $stats['ready'] > 0) {
            $signals[] = $this->signal('réussi', 'réussis', $stats['ready'], 'good');
        }

        return $this->state($stats['total'], 'déploiement', 'déploiements', $signals);
    }

    /**
     * Supervision : ce qui n'est PAS résolu.
     *
     * @return array<string, mixed>
     */
    private function errorState(string $organizationId): array
    {
        $stats = $this->stats('supervision', $organizationId, fn (): array => (new ErrorRepository())->statsForOrganization($organizationId));

        $signals = [];

        if ($stats['fatal'] > 0) {
            $signals[] = $this->signal('fatale', 'fatales', $stats['fatal'], 'alert');
        }

        if ($stats['events_24h'] > 0) {
            $signals[] = $this->signal('sur 24 h', 'sur 24 h', $stats['events_24h'], 'neutral');
        }

        if ($signals === [] && $stats['resolved'] > 0) {
            $signals[] = $this->signal('résolue', 'résolues', $stats['resolved'], 'good');
        }

        return $this->state($stats['unresolved'], 'non résolue', 'non résolues', $signals);
    }

    /**
     * Design : les fichiers, et l'épaisseur de leur historique.
     *
     * @return array<string, mixed>
     */
    private function designState(string $organizationId): array
    {
        $stats = $this->stats('design', $organizationId, fn (): array => (new DesignRepository())->statsForOrganization($organizationId));

        $signals = [];

        if ($stats['versions'] > 0) {
            $signals[] = $this->signal('version', 'versions', $stats['versions'], 'neutral');
        }

        if ($stats['updated_this_week'] > 0) {
            $signals[] = $this->signal('cette semaine', 'cette semaine', $stats['updated_this_week'], 'good');
        }

        return $this->state($stats['files'], 'fichier', 'fichiers', $signals);
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

        return $this->state(
            $total,
            'élément',
            'éléments',
            $active > 0 ? [$this->signal('actif', 'actifs', $active, 'neutral')] : [],
        );
    }

    /**
     * @param  list<array{label: string, value: int, tone: string}> $signals
     * @return array<string, mixed>
     */
    private function state(int $count, string $singular, string $plural, array $signals): array
    {
        return [
            'items_count' => $count,
            'unit'        => $count === 1 ? $singular : $plural,
            // Deux signaux au plus : une tuile de tableau de bord qui en
            // affiche cinq ne se lit plus d'un coup d'œil, ce qui est
            // pourtant sa seule raison d'être.
            'signals'     => array_slice($signals, 0, 2),
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
