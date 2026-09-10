<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DeploymentRepository;
use App\Models\ErrorRepository;
use App\Models\TicketRepository;

/**
 * Ce qui demande une action, tous modules confondus.
 *
 * Le tableau de bord promet « demande attention ». Ne montrer que les tickets
 * alors qu'une production est en échec tiendrait cette promesse à moitié, et
 * la moitié tue est justement celle qu'il fallait voir.
 *
 * Trois règles gouvernent ce fil :
 *
 *  1. TOUT VIENT D'UN FAIT, jamais d'une opinion. Une échéance dépassée, un
 *     déploiement en erreur, une exception non résolue : des choses qui se
 *     sont produites. La priorité « urgente » d'un ticket est la seule
 *     intention admise, et elle passe après les faits.
 *
 *  2. LA LISTE EST COURTE. Un tableau de bord qui signale tout ne signale
 *     rien : cinq lignes au total, quel qu'en soit le nombre disponible.
 *
 *  3. RIEN D'IGNORÉ NI DE TERMINÉ. Une alerte sur du travail déjà fait, ou
 *     sur un problème qu'on a explicitement décidé de laisser, coûte plus de
 *     confiance qu'elle n'en rapporte.
 */
final class AttentionFeed
{
    /**
     * Poids de tri : plus le nombre est petit, plus l'entrée remonte.
     *
     * L'ordre encode la règle 1 — les faits d'abord, l'intention ensuite.
     */
    private const WEIGHT = [
        'failed'  => 0, // déploiement en erreur : la production est cassée
        'fatal'   => 1, // exception fatale non résolue
        'overdue' => 2, // échéance dépassée : un fait
        'error'   => 3, // exception non résolue, niveau ordinaire
        'urgent'  => 4, // priorité déclarée : une intention
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function forOrganization(string $organizationId, int $limit = 5): array
    {
        $entries = [
            ...$this->fromDeployments($organizationId),
            ...$this->fromErrors($organizationId),
            ...$this->fromTickets($organizationId),
        ];

        usort($entries, function (array $a, array $b): int {
            $weight = self::WEIGHT[$a['reason']] <=> self::WEIGHT[$b['reason']];

            // À gravité égale, le plus récent d'abord : c'est celui sur lequel
            // on a le plus de chances d'agir utilement.
            return $weight !== 0 ? $weight : ($b['at'] ?? '') <=> ($a['at'] ?? '');
        });

        return array_slice($entries, 0, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fromTickets(string $organizationId): array
    {
        return array_map(
            static fn (array $ticket): array => [
                'module' => 'tickets',
                'id'     => $ticket['id'],
                'ref'    => '#' . $ticket['number'],
                'title'  => $ticket['title'],
                'reason' => $ticket['reason'],
                'at'     => $ticket['due_date'],
            ],
            (new TicketRepository())->needsAttention($organizationId, 5),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fromDeployments(string $organizationId): array
    {
        return array_map(
            static fn (array $deployment): array => [
                'module' => 'deploiement',
                'id'     => $deployment['id'],
                // La référence Git dit tout de suite QUOI a échoué, sans avoir
                // à ouvrir le déploiement.
                'ref'    => $deployment['branch'] . '@' . substr($deployment['commit_sha'], 0, 7),
                'title'  => $deployment['commit_message'] ?? 'Déploiement en échec',
                'reason' => 'failed',
                'at'     => $deployment['finished_at'] ?? $deployment['created_at'],
            ],
            (new DeploymentRepository())->needsAttention($organizationId, 3),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fromErrors(string $organizationId): array
    {
        return array_map(
            static fn (array $group): array => [
                'module' => 'supervision',
                'id'     => $group['id'],
                // Le nombre d'occurrences EST l'information : « 1 » et
                // « 4 128 » n'appellent pas la même réaction.
                'ref'    => '×' . $group['occurrences'],
                'title'  => $group['title'],
                'reason' => $group['level'] === 'fatal' ? 'fatal' : 'error',
                'at'     => $group['last_seen_at'],
            ],
            (new ErrorRepository())->needsAttention($organizationId, 3),
        );
    }
}
