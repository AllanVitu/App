<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * À qui revient un ticket.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  UNE SEULE RÈGLE DE SÉCURITÉ, ET TROIS FAÇONS DE LA MANQUER             │
 * │                                                                         │
 * │  L'assigné doit être MEMBRE de l'espace. Sans ce test, un identifiant   │
 * │  quelconque passerait, et la sous-requête qui résout le nom le          │
 * │  renverrait : on apprendrait le nom complet d'un compte étranger en     │
 * │  devinant son identifiant.                                              │
 * │                                                                         │
 * │  Le rôle, lui, n'entre PAS en jeu. Un simple membre confie un ticket à  │
 * │  n'importe quel autre — et le vérifier ici évite qu'une hiérarchie      │
 * │  s'installe par accident dans une prochaine passe.                      │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class AssignationTest extends ApiTestCase
{
    // =======================================================================
    //  Assigner
    // =======================================================================

    #[Test]
    public function un_ticket_naît_sans_assigné(): void
    {
        $session = $this->register('solo@test.local');

        $ticket = $this->creer($session['token'], ['title' => 'À prendre']);

        // NULL est un ÉTAT — « à personne » — et non une donnée manquante :
        // c'est ce que dit la file d'attente d'une équipe.
        $this->assertNull($ticket['assigned_to']);
        $this->assertNull($ticket['assignee_name']);
    }

    #[Test]
    public function un_membre_peut_confier_un_ticket_a_un_coequipier(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe();

        $ticket = $this->creer($hote['token'], ['title' => 'Pour toi']);

        $reponse = $this->call(
            'PUT',
            '/api/tickets/' . $ticket['id'],
            ['assigned_to' => $invite['id']],
            $this->bearer($hote['token']),
        );

        $this->assertSame(200, $reponse['status']);
        $this->assertSame($invite['id'], $reponse['body']['data']['assigned_to']);
        $this->assertSame('Coéquipier', $reponse['body']['data']['assignee_name']);

        // L'auteur ne bouge pas : « qui l'a ouvert » et « à qui il revient »
        // sont deux faits distincts, et l'assignation n'en efface aucun.
        $this->assertSame($hote['id'], $reponse['body']['data']['created_by']);
        $this->assertSame('Hôte', $reponse['body']['data']['author_name']);
    }

    #[Test]
    public function un_simple_membre_assigne_comme_les_autres(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe();

        $ticket = $this->creer($hote['token'], ['title' => 'Je m\'en occupe']);

        // L'invité est « member », le rang le plus bas. Il s'attribue le
        // ticket d'un autre sans rien demander : c'est voulu.
        $reponse = $this->call(
            'PUT',
            '/api/tickets/' . $ticket['id'],
            ['assigned_to' => $invite['id']],
            $this->bearer($invite['token']),
        );

        $this->assertSame(200, $reponse['status']);
        $this->assertSame($invite['id'], $reponse['body']['data']['assigned_to']);
    }

    #[Test]
    public function un_ticket_se_rend_a_la_file(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe();

        $ticket = $this->creer($hote['token'], ['title' => 'Finalement non']);

        $this->call(
            'PUT',
            '/api/tickets/' . $ticket['id'],
            ['assigned_to' => $invite['id']],
            $this->bearer($hote['token']),
        );

        // « null » EXPLICITE, et non l'omission du champ : rendre un ticket à
        // la file est un geste courant, il doit passer par le même chemin que
        // l'assignation.
        $reponse = $this->call(
            'PUT',
            '/api/tickets/' . $ticket['id'],
            ['assigned_to' => null],
            $this->bearer($hote['token']),
        );

        $this->assertSame(200, $reponse['status']);
        $this->assertNull($reponse['body']['data']['assigned_to']);
    }

    #[Test]
    public function un_champ_absent_conserve_l_assignation(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe();

        $ticket = $this->creer($hote['token'], ['title' => 'Priorité à changer']);

        $this->call(
            'PUT',
            '/api/tickets/' . $ticket['id'],
            ['assigned_to' => $invite['id']],
            $this->bearer($hote['token']),
        );

        // Le contrat de mise à jour PARTIELLE : changer la priorité au clavier
        // ne doit pas désassigner le ticket au passage.
        $reponse = $this->call(
            'PUT',
            '/api/tickets/' . $ticket['id'],
            ['priority' => 'urgent'],
            $this->bearer($hote['token']),
        );

        $this->assertSame('urgent', $reponse['body']['data']['priority']);
        $this->assertSame($invite['id'], $reponse['body']['data']['assigned_to']);
    }

    #[Test]
    public function un_ticket_peut_naître_deja_assigné(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe();

        $ticket = $this->creer($hote['token'], [
            'title'       => 'Confié dès l\'ouverture',
            'assigned_to' => $invite['id'],
        ]);

        $this->assertSame($invite['id'], $ticket['assigned_to']);
    }

    // =======================================================================
    //  Ce que l'assignation refuse
    // =======================================================================

    #[Test]
    public function on_ne_peut_pas_assigner_hors_de_l_espace(): void
    {
        $hote     = $this->register('interne@test.local');
        $etranger = $this->register('externe@test.local', name: 'Personne Extérieure');

        $ticket = $this->creer($hote['token'], ['title' => 'Interne']);

        $reponse = $this->call(
            'PUT',
            '/api/tickets/' . $ticket['id'],
            ['assigned_to' => $etranger['id']],
            $this->bearer($hote['token']),
        );

        $this->assertSame(422, $reponse['status']);
        $this->assertArrayHasKey('assigned_to', $reponse['body']['errors']);

        // ET LE NOM N'A PAS FUITÉ. C'est la raison d'être du test : sans la
        // vérification d'appartenance, la réponse aurait porté « Personne
        // Extérieure », révélant le nom complet d'un compte étranger à qui
        // devine son identifiant.
        $this->assertStringNotContainsString(
            'Personne Extérieure',
            json_encode($reponse['body'], JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function un_identifiant_qui_n_est_pas_un_identifiant_est_refuse(): void
    {
        $session = $this->register('malforme@test.local');
        $ticket  = $this->creer($session['token'], ['title' => 'Test']);

        $reponse = $this->call(
            'PUT',
            '/api/tickets/' . $ticket['id'],
            ['assigned_to' => 'moi'],
            $this->bearer($session['token']),
        );

        $this->assertSame(422, $reponse['status']);
        $this->assertArrayHasKey('assigned_to', $reponse['body']['errors']);
    }

    #[Test]
    public function un_membre_exclu_libere_ses_tickets(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe();

        $ticket = $this->creer($hote['token'], ['title' => 'Confié puis orphelin']);

        $this->call(
            'PUT',
            '/api/tickets/' . $ticket['id'],
            ['assigned_to' => $invite['id']],
            $this->bearer($hote['token']),
        );

        // Le compte disparaît entièrement — le cas extrême, plus dur que
        // l'exclusion.
        Database::connection()
            ->prepare('DELETE FROM users WHERE id = :id')
            ->execute(['id' => $invite['id']]);

        $relu = $this->call(
            'GET',
            '/api/tickets/' . $ticket['id'],
            headers: $this->bearer($hote['token']),
        );

        // ON DELETE SET NULL : le ticket SURVIT et retourne à la file. Une
        // cascade l'aurait emporté avec son assigné, ce qui reviendrait à
        // perdre le travail de l'équipe au départ d'une personne.
        $this->assertSame(200, $relu['status']);
        $this->assertNull($relu['body']['data']['assigned_to']);
        $this->assertNull($relu['body']['data']['assignee_name']);
    }

    // =======================================================================
    //  Filtrer
    // =======================================================================

    #[Test]
    public function le_filtre_me_designe_toujours_celui_qui_lit(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe();

        $aMoi = $this->creer($hote['token'], ['title' => 'À l\'hôte']);
        $aLui = $this->creer($hote['token'], ['title' => 'À l\'invité']);

        $this->assigner($hote['token'], $aMoi['id'], $hote['id']);
        $this->assigner($hote['token'], $aLui['id'], $invite['id']);

        // LA MÊME ADRESSE, DEUX LECTEURS, DEUX RÉSULTATS — et c'est tout
        // l'intérêt du raccourci. Avec un identifiant en clair dans l'URL, un
        // lien « mes tickets » envoyé à un collègue lui aurait montré les
        // vôtres en lui laissant croire qu'il regardait les siens.
        $vueHote   = $this->lister($hote['token'], ['assignee' => 'me']);
        $vueInvite = $this->lister($invite['token'], ['assignee' => 'me']);

        $this->assertSame(['À l\'hôte'], array_column($vueHote['body']['data'], 'title'));
        $this->assertSame(['À l\'invité'], array_column($vueInvite['body']['data'], 'title'));
    }

    #[Test]
    public function le_filtre_none_donne_la_file_d_attente(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe();

        $pris = $this->creer($hote['token'], ['title' => 'Pris']);
        $this->creer($hote['token'], ['title' => 'Libre']);

        $this->assigner($hote['token'], $pris['id'], $invite['id']);

        $liste = $this->lister($hote['token'], ['assignee' => 'none']);

        // « Personne » est un FILTRE, pas l'absence de filtre : sans cette
        // distinction, la file d'attente d'une équipe serait inatteignable.
        $this->assertSame(['Libre'], array_column($liste['body']['data'], 'title'));
    }

    #[Test]
    public function le_filtre_designe_aussi_un_coequipier(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe();

        $sien = $this->creer($hote['token'], ['title' => 'Le sien']);
        $this->creer($hote['token'], ['title' => 'Pas le sien']);

        $this->assigner($hote['token'], $sien['id'], $invite['id']);

        $liste = $this->lister($hote['token'], ['assignee' => $invite['id']]);

        $this->assertSame(['Le sien'], array_column($liste['body']['data'], 'title'));
    }

    #[Test]
    public function un_filtre_malforme_est_une_erreur_et_non_une_liste_entiere(): void
    {
        $session = $this->register('filtre@test.local');
        $this->creer($session['token'], ['title' => 'Visible']);

        $reponse = $this->lister($session['token'], ['assignee' => 'n-importe-quoi']);

        // Renvoyer la liste complète alors qu'on la croit restreinte est le
        // plus trompeur des deux comportements — même règle que pour les
        // filtres de statut et de priorité.
        $this->assertSame(422, $reponse['status']);
    }

    #[Test]
    public function les_compteurs_disent_ce_qui_m_attend_et_ce_qui_n_attend_personne(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe();

        $aMoi = $this->creer($hote['token'], ['title' => 'À moi']);
        $aLui = $this->creer($hote['token'], ['title' => 'À lui']);
        $this->creer($hote['token'], ['title' => 'À personne']);

        $this->assigner($hote['token'], $aMoi['id'], $hote['id']);
        $this->assigner($hote['token'], $aLui['id'], $invite['id']);

        $stats = $this->lister($hote['token'])['body']['meta']['stats'];

        $this->assertSame(1, $stats['mine']);
        $this->assertSame(1, $stats['unassigned']);

        // « mine » suit le LECTEUR, comme le filtre : les deux doivent dire la
        // même chose, sans quoi la pastille annoncerait un nombre que la liste
        // ne montrerait pas.
        $this->assertSame(1, $this->lister($invite['token'])['body']['meta']['stats']['mine']);
    }

    #[Test]
    public function un_ticket_clos_ne_compte_plus_parmi_les_miens(): void
    {
        $session = $this->register('clos@test.local');

        $ticket = $this->creer($session['token'], ['title' => 'Terminé']);
        $this->assigner($session['token'], $ticket['id'], $session['id']);

        $this->assertSame(1, $this->lister($session['token'])['body']['meta']['stats']['mine']);

        $this->call(
            'PUT',
            '/api/tickets/' . $ticket['id'],
            ['status' => 'done'],
            $this->bearer($session['token']),
        );

        // Un ticket clos ne demande plus rien : le compter parmi « les miens »
        // ferait une pastille qui ne redescend jamais.
        $this->assertSame(0, $this->lister($session['token'])['body']['meta']['stats']['mine']);
    }

    // =======================================================================

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function creer(string $token, array $payload): array
    {
        $reponse = $this->call('POST', '/api/tickets', $payload, $this->bearer($token));

        $this->assertSame(201, $reponse['status'], 'création de ticket refusée');

        return $reponse['body']['data'];
    }

    private function assigner(string $token, string $ticketId, ?string $userId): void
    {
        $reponse = $this->call(
            'PUT',
            "/api/tickets/{$ticketId}",
            ['assigned_to' => $userId],
            $this->bearer($token),
        );

        $this->assertSame(200, $reponse['status'], 'assignation refusée');
    }

    /**
     * @param array<string, string> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    private function lister(string $token, array $query = []): array
    {
        return $this->call('GET', '/api/tickets', [], $this->bearer($token), $query);
    }

    /**
     * Un espace à deux, par le vrai parcours d'invitation.
     *
     * @return array{hote: array{token: string, id: string, email: string, org: string},
     *               invite: array{token: string, id: string, email: string, org: string}}
     */
    private function equipe(): array
    {
        $hote = $this->register('hote@test.local', name: 'Hôte');

        $this->call('POST', '/api/organizations/invitations', [
            'email' => 'coequipier@test.local',
        ], $this->bearer($hote['token']));

        $statement = Database::connection()->query(
            "SELECT payload FROM jobs
              WHERE type = 'mail.send' AND payload->>'to' = 'coequipier@test.local'
           ORDER BY created_at DESC LIMIT 1",
        );

        $payload = json_decode((string) $statement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($payload, 'aucune invitation déposée en file');

        preg_match('/token=([a-f0-9]{64})/', (string) $payload['text'], $trouve);

        $invite = $this->register('coequipier@test.local', name: 'Coéquipier');

        $this->call(
            'POST',
            "/api/invitations/{$trouve[1]}/accept",
            headers: $this->bearer($invite['token']),
        );

        return ['hote' => $hote, 'invite' => ['org' => $hote['org']] + $invite];
    }
}
