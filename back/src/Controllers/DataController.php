<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\SchemaBuilder;

/**
 * Les données des tables créées dans le module Backend.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  C'EST CE QUI REND LE MODULE UTILE                                  │
 * │                                                                     │
 * │  Décrire une table sans pouvoir y écrire ni y lire, c'est un        │
 * │  éditeur de diagrammes. Ces trois routes en font un backend :       │
 * │  une application tierce peut enfin s'en servir, avec une clé d'API  │
 * │  de service — la seule authentification qu'un serveur puisse        │
 * │  détenir (cf. IngestMiddleware).                                    │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * LE NOM DE LA TABLE VIENT DE L'URL, donc de l'extérieur. Il n'est jamais
 * concaténé ici : SchemaBuilder le revalide contre un motif d'identifiant
 * strict avant d'en faire quoi que ce soit, et refuse tout le reste. Ce
 * contrôleur ne fait que transmettre.
 *
 * PAS DE MISE À JOUR PARTIELLE (PATCH/PUT) pour l'instant, et c'est délibéré :
 * modifier une ligne demande de décider quoi faire d'un champ absent — le
 * mettre à NULL ou le laisser — et cette décision se prend une fois, pas au
 * hasard des appels. Créer et supprimer suffisent à prototyper.
 */
final class DataController
{
    private SchemaBuilder $schema;

    public function __construct()
    {
        $this->schema = new SchemaBuilder();
    }

    /**
     * GET /api/backend/data/{table}
     */
    public function index(Request $request): void
    {
        $table  = $this->tableName($request);
        $userId = $request->userId();

        $result = $this->schema->rows($userId, $table);

        Response::json($result['rows'], 200, [
            'table' => $table,
            // Le total est celui de la BASE, la liste est plafonnée : les
            // comparer est le seul moyen de savoir qu'on a coupé.
            'total'   => $result['total'],
            'columns' => $this->schema->physicalColumns($userId, $table),
        ]);
    }

    /**
     * POST /api/backend/data/{table}
     */
    public function store(Request $request): void
    {
        $valeurs = $request->all();

        // « all() » renvoie toujours un tableau : seul le vide est un cas réel.
        if ($valeurs === []) {
            throw HttpException::validation(
                ['row' => 'Envoyez un objet JSON dont les clés sont des colonnes de la table.'],
                'Corps de requête vide.',
            );
        }

        Response::created(
            $this->schema->insert($request->userId(), $this->tableName($request), $valeurs),
        );
    }

    /**
     * DELETE /api/backend/data/{table}/{id}
     */
    public function destroy(Request $request): void
    {
        $supprime = $this->schema->delete(
            $request->userId(),
            $this->tableName($request),
            (string) $request->param('id'),
        );

        if (!$supprime) {
            throw HttpException::notFound('Aucune ligne ne porte cet identifiant.');
        }

        Response::noContent();
    }

    /**
     * Le nom brut de l'URL. La validation appartient à SchemaBuilder, qui est
     * le dernier endroit avant la base — la dupliquer ici donnerait deux
     * définitions du même motif, vouées à diverger.
     */
    private function tableName(Request $request): string
    {
        return (string) $request->param('table');
    }
}
