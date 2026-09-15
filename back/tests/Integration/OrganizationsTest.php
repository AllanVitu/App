<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Espaces de travail : appartenance, rôles, invitations.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  CE FICHIER VÉRIFIE UN CHANGEMENT DE FRONTIÈRE, PAS UNE FONCTIONNALITÉ  │
 * │                                                                         │
 * │  Les autres fichiers vérifient qu'un compte ne voit pas les données     │
 * │  d'un autre. Cette garantie tenait à « user_id ». Elle tient désormais  │
 * │  à « organization_id », et la nuance a une conséquence NOUVELLE et      │
 * │  volontaire : deux comptes du MÊME espace se voient.                    │
 * │                                                                         │
 * │  C'est le cas positif — celui qu'aucun test ne pouvait exprimer avant,  │
 * │  et celui qui casserait sans bruit si une requête gardait un filtre sur │
 * │  la personne. Il est donc vérifié ici sur les cinq modules, et pas      │
 * │  seulement sur un.                                                      │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class OrganizationsTest extends ApiTestCase
{
    // =======================================================================
    //  L'espace naît avec le compte
    // =======================================================================

    #[Test]
    public function une_inscription_cree_un_espace_dont_le_compte_est_proprietaire(): void
    {
        $session = $this->register('fondateur@test.local');

        $reponse = $this->call('GET', '/api/organizations', headers: $this->bearer($session['token']));

        $this->assertSame(200, $reponse['status']);
        $this->assertCount(1, $reponse['body']['data']);
        $this->assertSame('owner', $reponse['body']['data'][0]['role']);
        $this->assertSame(1, $reponse['body']['data'][0]['members']);
        $this->assertSame($session['org'], $reponse['body']['meta']['current']['id']);
    }

    #[Test]
    public function deux_espaces_ne_peuvent_pas_partager_un_slug(): void
    {
        // Même nom, deux comptes : le slug doit être rendu unique sans que
        // l'un des deux échoue.
        $premier = $this->register('slug-a@test.local', name: 'Atelier Dupont');
        $second  = $this->register('slug-b@test.local', name: 'Atelier Dupont');

        $statement = Database::connection()->prepare(
            'SELECT slug FROM organizations WHERE id IN (:a, :b) ORDER BY slug',
        );
        $statement->execute(['a' => $premier['org'], 'b' => $second['org']]);
        $slugs = $statement->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertCount(2, array_unique($slugs));
    }

    // =======================================================================
    //  Ce que l'invitation change
    // =======================================================================

    #[Test]
    public function un_membre_invite_voit_les_donnees_des_cinq_modules(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe();

        // L'hôte crée quelque chose dans chacun des cinq modules. Chaque
        // création est vérifiée : sans cela, un échec de saisie se lirait plus
        // bas comme un échec de PARTAGE, et enverrait chercher le défaut au
        // mauvais endroit.
        $creations = [
            ['/api/tickets', ['title' => 'Ticket partagé']],
            ['/api/backend/tables', ['name' => 'partagee', 'columns' => [['name' => 'id', 'type' => 'uuid']]]],
            ['/api/deployments', ['environment' => 'production', 'branch' => 'main', 'commit_sha' => 'abc1234']],
            ['/api/design/files', ['name' => 'Maquette partagée']],
            ['/api/errors', [
                'fingerprint' => 'partage-erreur',
                'title'       => 'TypeError: partage',
                'message'     => 'Erreur partagée',
                'level'       => 'error',
            ]],
        ];

        foreach ($creations as [$url, $payload]) {
            $reponse = $this->call('POST', $url, $payload, $this->bearer($hote['token']));

            $this->assertSame(201, $reponse['status'], "création refusée sur {$url}");
        }

        // L'invité les voit toutes : c'est la promesse même d'un espace commun.
        $entete = $this->bearer($invite['token']);

        $this->assertCount(1, $this->call('GET', '/api/tickets', headers: $entete)['body']['data']);
        $this->assertCount(1, $this->call('GET', '/api/backend/tables', headers: $entete)['body']['data']);
        $this->assertCount(1, $this->call('GET', '/api/deployments', headers: $entete)['body']['data']);
        $this->assertCount(1, $this->call('GET', '/api/design/files', headers: $entete)['body']['data']);
        $this->assertCount(1, $this->call('GET', '/api/errors', headers: $entete)['body']['data']);
    }

    #[Test]
    public function un_ticket_garde_le_nom_de_qui_l_a_ouvert(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe();

        $this->call('POST', '/api/tickets', ['title' => 'Écrit par l\'hôte'], $this->bearer($hote['token']));

        $liste  = $this->call('GET', '/api/tickets', headers: $this->bearer($invite['token']));
        $ticket = $liste['body']['data'][0];

        // Le voir ne suffit pas : à plusieurs, savoir QUI l'a ouvert est la
        // première question qu'on se pose devant un ticket qu'on n'a pas écrit.
        $this->assertSame('Hôte', $ticket['author_name']);
        $this->assertSame($hote['id'], $ticket['created_by']);
    }

    #[Test]
    public function les_numeros_de_tickets_sont_une_suite_unique_pour_l_equipe(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe();

        $premier = $this->call('POST', '/api/tickets', ['title' => 'Un'], $this->bearer($hote['token']));
        $second  = $this->call('POST', '/api/tickets', ['title' => 'Deux'], $this->bearer($invite['token']));

        // Le compteur est celui de l'ESPACE : deux personnes n'obtiennent
        // jamais le même numéro, et « ouvre 2 » désigne le même ticket pour
        // tout le monde.
        $this->assertSame(1, $premier['body']['data']['number']);
        $this->assertSame(2, $second['body']['data']['number']);
    }

    #[Test]
    public function un_compte_hors_de_l_espace_ne_voit_rien(): void
    {
        ['hote' => $hote] = $this->equipe();

        $etranger = $this->register('etranger@test.local');

        $this->call('POST', '/api/tickets', ['title' => 'Privé'], $this->bearer($hote['token']));

        $liste = $this->call('GET', '/api/tickets', headers: $this->bearer($etranger['token']));

        $this->assertSame([], $liste['body']['data']);
    }

    // =======================================================================
    //  Les rôles gouvernent enfin quelque chose
    // =======================================================================

    #[Test]
    public function un_membre_ne_peut_pas_inviter(): void
    {
        ['invite' => $invite] = $this->equipe();

        $reponse = $this->call('POST', '/api/organizations/invitations', [
            'email' => 'tiers@test.local',
        ], $this->bearer($invite['token']));

        $this->assertSame(403, $reponse['status']);
    }

    #[Test]
    public function un_administrateur_invite_mais_ne_supprime_pas_l_espace(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe(role: 'admin');

        $invitation = $this->call('POST', '/api/organizations/invitations', [
            'email' => 'quatrieme@test.local',
        ], $this->bearer($invite['token']));

        $this->assertSame(201, $invitation['status']);

        $suppression = $this->call(
            'DELETE',
            '/api/organizations/' . $hote['org'],
            headers: $this->bearer($invite['token']),
        );

        $this->assertSame(403, $suppression['status']);
    }

    #[Test]
    public function un_administrateur_ne_touche_pas_a_un_proprietaire(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe(role: 'admin');

        $reponse = $this->call('PUT', '/api/organizations/members/' . $hote['id'], [
            'role' => 'member',
        ], $this->bearer($invite['token']));

        // Sans cette règle, le rang de propriétaire ne voudrait rien dire :
        // n'importe quel administrateur s'y hisserait en rétrogradant l'autre.
        $this->assertSame(403, $reponse['status']);
    }

    #[Test]
    public function la_propriete_se_transmet_et_l_ancien_proprietaire_perd_ses_droits(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe();

        // L'hôte nomme un second propriétaire…
        $promotion = $this->call('PUT', '/api/organizations/members/' . $invite['id'], [
            'role' => 'owner',
        ], $this->bearer($hote['token']));
        $this->assertSame(200, $promotion['status']);

        // …qui peut alors le rétrograder : il en reste un, l'espace n'est
        // jamais sans propriétaire. C'est la seule façon de transmettre.
        $passation = $this->call('PUT', '/api/organizations/members/' . $hote['id'], [
            'role' => 'member',
        ], $this->bearer($invite['token']));
        $this->assertSame(200, $passation['status']);

        // Et la rétrogradation MORD : l'ancien propriétaire ne peut plus
        // supprimer l'espace. Sans cette vérification, le test ne prouverait
        // que l'écriture d'une ligne.
        $suppression = $this->call(
            'DELETE',
            '/api/organizations/' . $hote['org'],
            headers: $this->bearer($hote['token']),
        );

        $this->assertSame(403, $suppression['status']);
    }

    #[Test]
    public function on_ne_modifie_pas_son_propre_role(): void
    {
        ['invite' => $invite] = $this->equipe(role: 'admin');

        $reponse = $this->call('PUT', '/api/organizations/members/' . $invite['id'], [
            'role' => 'owner',
        ], $this->bearer($invite['token']));

        $this->assertSame(409, $reponse['status']);
    }

    #[Test]
    public function le_dernier_proprietaire_ne_peut_pas_quitter_l_espace(): void
    {
        ['hote' => $hote] = $this->equipe();

        $reponse = $this->call('POST', '/api/organizations/leave', headers: $this->bearer($hote['token']));

        // Il est propriétaire unique de l'espace partagé ET de son espace
        // personnel ; c'est la première règle qui l'arrête.
        $this->assertSame(409, $reponse['status']);
    }

    #[Test]
    public function un_membre_peut_quitter_l_espace_et_cesse_d_en_voir_le_contenu(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe();

        $this->call('POST', '/api/tickets', ['title' => 'Interne'], $this->bearer($hote['token']));

        $depart = $this->call('POST', '/api/organizations/leave', headers: $this->bearer($invite['token']));
        $this->assertSame(204, $depart['status']);

        // Il retombe sur son propre espace, où il n'y a rien.
        $liste = $this->call('GET', '/api/tickets', headers: $this->bearer($invite['token']));

        $this->assertSame([], $liste['body']['data']);
    }

    // =======================================================================
    //  Bascule d'espace
    // =======================================================================

    #[Test]
    public function on_ne_peut_pas_activer_un_espace_dont_on_n_est_pas_membre(): void
    {
        $mien   = $this->register('bascule-mien@test.local');
        $autrui = $this->register('bascule-autrui@test.local');

        $reponse = $this->call(
            'POST',
            '/api/organizations/' . $autrui['org'] . '/activate',
            headers: $this->bearer($mien['token']),
        );

        // 404 et non 403 : « interdit » confirmerait que cet espace existe.
        $this->assertSame(404, $reponse['status']);
    }

    #[Test]
    public function basculer_d_espace_change_ce_que_l_on_voit(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe();

        $this->call('POST', '/api/tickets', ['title' => 'Chez l\'hôte'], $this->bearer($hote['token']));

        // L'invité est dans l'espace partagé : il voit le ticket.
        $this->assertCount(1, $this->call('GET', '/api/tickets', headers: $this->bearer($invite['token']))['body']['data']);

        // Il repasse sur le sien.
        $sien = $this->call('GET', '/api/organizations', headers: $this->bearer($invite['token']));
        $autre = null;

        foreach ($sien['body']['data'] as $organisation) {
            if ($organisation['id'] !== $hote['org']) {
                $autre = $organisation['id'];
            }
        }

        $this->assertNotNull($autre, 'l\'invité garde son propre espace');

        $this->call(
            'POST',
            '/api/organizations/' . $autre . '/activate',
            headers: $this->bearer($invite['token']),
        );

        $this->assertSame([], $this->call('GET', '/api/tickets', headers: $this->bearer($invite['token']))['body']['data']);
    }

    // =======================================================================
    //  Invitations
    // =======================================================================

    #[Test]
    public function une_invitation_ne_sert_qu_une_fois(): void
    {
        $hote  = $this->register('unique-hote@test.local');
        $token = $this->inviter($hote, 'unique-invite@test.local');

        $invite = $this->register('unique-invite@test.local');

        $premiere = $this->call(
            'POST',
            "/api/invitations/{$token}/accept",
            headers: $this->bearer($invite['token']),
        );
        $this->assertSame(200, $premiere['status']);

        $seconde = $this->call(
            'POST',
            "/api/invitations/{$token}/accept",
            headers: $this->bearer($invite['token']),
        );

        $this->assertSame(404, $seconde['status']);
    }

    #[Test]
    public function une_invitation_expiree_est_refusee(): void
    {
        $hote  = $this->register('perimee-hote@test.local');
        $token = $this->inviter($hote, 'perimee-invite@test.local');

        // Vieillie en base plutôt qu'attendue sept jours : c'est la date qui
        // fait foi, et c'est elle que le test déplace.
        Database::connection()->exec("UPDATE invitations SET expires_at = NOW() - INTERVAL '1 day'");

        $invite = $this->register('perimee-invite@test.local');

        $reponse = $this->call(
            'POST',
            "/api/invitations/{$token}/accept",
            headers: $this->bearer($invite['token']),
        );

        $this->assertSame(404, $reponse['status']);
    }

    #[Test]
    public function l_ecran_d_accueil_d_une_invitation_est_public(): void
    {
        $hote  = $this->register('accueil-hote@test.local', name: 'Studio Nord');
        $token = $this->inviter($hote, 'accueil-invite@test.local');

        // SANS aucun en-tête d'authentification : celui qui ouvre le lien n'a
        // le plus souvent pas encore de compte.
        $reponse = $this->call('GET', "/api/invitations/{$token}");

        $this->assertSame(200, $reponse['status']);
        $this->assertSame('Studio Nord', $reponse['body']['data']['organization_name']);
        $this->assertSame('accueil-invite@test.local', $reponse['body']['data']['email']);
        // Rien de plus que ce que le porteur du lien sait déjà.
        $this->assertArrayNotHasKey('members', $reponse['body']['data']);
    }

    #[Test]
    public function une_invitation_inconnue_ne_dit_pas_pourquoi(): void
    {
        $reponse = $this->call('GET', '/api/invitations/' . str_repeat('0', 64));

        $this->assertSame(404, $reponse['status']);
    }

    #[Test]
    public function inviter_un_membre_deja_present_est_refuse(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe();

        $reponse = $this->call('POST', '/api/organizations/invitations', [
            'email' => $invite['email'],
        ], $this->bearer($hote['token']));

        $this->assertSame(409, $reponse['status']);
    }

    #[Test]
    public function reinviter_remplace_le_lien_precedent(): void
    {
        $hote    = $this->register('reinvite-hote@test.local');
        $premier = $this->inviter($hote, 'reinvite@test.local');
        $second  = $this->inviter($hote, 'reinvite@test.local');

        $this->assertNotSame($premier, $second);

        // Le premier lien cesse de fonctionner : une invitation envoyée par
        // erreur doit pouvoir être annulée en la renvoyant.
        $this->assertSame(404, $this->call('GET', "/api/invitations/{$premier}")['status']);
        $this->assertSame(200, $this->call('GET', "/api/invitations/{$second}")['status']);
    }

    #[Test]
    public function une_invitation_revoquee_ne_vaut_plus_rien(): void
    {
        $hote  = $this->register('revoque-hote@test.local');
        $token = $this->inviter($hote, 'revoque@test.local');

        $liste = $this->call('GET', '/api/organizations/members', headers: $this->bearer($hote['token']));
        $id    = $liste['body']['meta']['invitations'][0]['id'];

        $this->call(
            'DELETE',
            "/api/organizations/invitations/{$id}",
            headers: $this->bearer($hote['token']),
        );

        $this->assertSame(404, $this->call('GET', "/api/invitations/{$token}")['status']);
    }

    #[Test]
    public function les_invitations_en_attente_ne_sont_visibles_que_des_administrateurs(): void
    {
        ['hote' => $hote, 'invite' => $invite] = $this->equipe();

        $this->inviter($hote, 'en-attente@test.local');

        $vueAdmin  = $this->call('GET', '/api/organizations/members', headers: $this->bearer($hote['token']));
        $vueMembre = $this->call('GET', '/api/organizations/members', headers: $this->bearer($invite['token']));

        // Les MEMBRES sont visibles de tous — savoir avec qui l'on travaille
        // n'est pas un privilège. Les invitations en cours, si.
        $this->assertCount(2, $vueMembre['body']['data']);
        $this->assertCount(1, $vueAdmin['body']['meta']['invitations']);
        $this->assertSame([], $vueMembre['body']['meta']['invitations']);
    }

    #[Test]
    public function la_base_ne_garde_que_l_empreinte_du_jeton_d_invitation(): void
    {
        $hote  = $this->register('empreinte-hote@test.local');
        $token = $this->inviter($hote, 'empreinte@test.local');

        $statement = Database::connection()->query('SELECT token_hash FROM invitations');
        $stocke    = (string) $statement->fetchColumn();

        // Même règle que pour les jetons de session : une fuite de la table ne
        // permet pas de rejouer une invitation.
        $this->assertSame(hash('sha256', $token), $stocke);
        $this->assertNotSame($token, $stocke);
    }

    // =======================================================================

    /**
     * Un espace à deux : l'hôte propriétaire, l'invité au rôle demandé.
     *
     * Passe par le VRAI parcours — invitation, inscription, acceptation —
     * plutôt que par des écritures directes en base : c'est ce parcours qui
     * doit fonctionner, et un raccourci ici ne prouverait rien de lui.
     *
     * @return array{hote: array{token: string, id: string, email: string, org: string},
     *               invite: array{token: string, id: string, email: string, org: string}}
     */
    private function equipe(string $role = 'member'): array
    {
        $hote  = $this->register('hote@test.local', name: 'Hôte');
        $token = $this->inviter($hote, 'coequipier@test.local', $role);

        $invite = $this->register('coequipier@test.local', name: 'Coéquipier');

        $this->call(
            'POST',
            "/api/invitations/{$token}/accept",
            headers: $this->bearer($invite['token']),
        );

        // L'espace actif de l'invité est désormais celui de l'hôte : c'est
        // l'acceptation qui l'a basculé, et le reste du test en dépend.
        return ['hote' => $hote, 'invite' => ['org' => $hote['org']] + $invite];
    }

    /**
     * Émet une invitation et renvoie le jeton EN CLAIR.
     *
     * L'API ne le renvoie jamais — il ne vit que dans l'e-mail. Le test le
     * reconstitue donc en interceptant la tâche déposée en file, ce qui a
     * l'avantage de vérifier au passage que l'e-mail part réellement.
     *
     * @param array{token: string, id: string, email: string, org: string} $hote
     */
    private function inviter(array $hote, string $email, string $role = 'member'): string
    {
        $reponse = $this->call('POST', '/api/organizations/invitations', [
            'email' => $email,
            'role'  => $role,
        ], $this->bearer($hote['token']));

        $this->assertSame(201, $reponse['status'], 'invitation refusée');

        $statement = Database::connection()->prepare(
            "SELECT payload FROM jobs
              WHERE type = 'mail.send' AND payload->>'to' = :email
           ORDER BY created_at DESC
              LIMIT 1",
        );
        $statement->execute(['email' => $email]);

        $payload = json_decode((string) $statement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($payload, 'aucun e-mail déposé en file');

        preg_match('/token=([a-f0-9]{64})/', (string) $payload['text'], $trouve);

        $this->assertNotEmpty($trouve, 'le lien d\'invitation ne porte pas de jeton');

        return $trouve[1];
    }
}
