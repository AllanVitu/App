<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\TicketCommentRepository;
use App\Models\TicketRepository;
use App\Services\Journal;
use App\Services\RateLimiter;

/**
 * La discussion d'un ticket.
 *
 * Tout membre commente. On ne modifie pas un commentaire : on le supprime et
 * on en écrit un autre — une réponse qui change sous la phrase qui lui
 * répondait rendrait le fil faux. Seul l'auteur supprime le sien ; un
 * propriétaire ou un administrateur peut retirer celui de n'importe qui, parce
 * qu'il faut bien que quelqu'un puisse retirer un secret collé par erreur.
 */
final class TicketCommentController
{
    /** La contrainte « ticket_comments_body_len ». */
    private const BODY_MAX = 5000;

    private TicketRepository $tickets;
    private TicketCommentRepository $comments;
    private Journal $journal;

    public function __construct()
    {
        $this->tickets  = new TicketRepository();
        $this->comments = new TicketCommentRepository();
        // Le fil appartient au TICKET : le fait se range dans le module Tickets,
        // sous la référence du ticket, et se lit « a commenté TICK-12 ».
        $this->journal = new Journal('tickets', []);
    }

    /**
     * GET /api/tickets/{id}/comments
     */
    public function index(Request $request): void
    {
        $ticket = $this->ticketOrFail($request);

        Response::json($this->comments->listForTicket((string) $ticket['id'], $request->organizationId()));
    }

    /**
     * POST /api/tickets/{id}/comments
     */
    public function store(Request $request): void
    {
        $ticket = $this->ticketOrFail($request);

        // Trente par minute : une conversation vive tient dessous, un script
        // qui boucle non.
        (new RateLimiter())->hit('ticket-comment', $request->userId(), 30, 60);

        $validator = new Validator($request->all());
        $body      = $request->input('body');

        if (!is_string($body) || trim($body) === '') {
            $validator->addError('body', 'Le commentaire est vide.');
        } elseif (mb_strlen($body) > self::BODY_MAX) {
            $validator->addError('body', 'Un commentaire tient en cinq mille caractères : une page de Documentation accueille le reste.');
        }

        $validator->check();

        $comment = $this->comments->create(
            (string) $ticket['id'],
            $request->organizationId(),
            $request->actorId(),
            // Tel qu'il a été écrit, sauf les blancs de bord : un bloc de code
            // garde son indentation, une ligne vide finale ne compte pas.
            rtrim((string) $body),
        );

        // Le journal garde QU'on a commenté, pas le texte : l'historique d'une
        // équipe n'est pas une seconde copie de ses conversations.
        $this->journal->record(
            $request,
            'commented',
            (string) $ticket['id'],
            'TICK-' . $ticket['number'],
            (string) $ticket['title'],
        );

        Response::created($comment);
    }

    /**
     * DELETE /api/tickets/{id}/comments/{comment}
     */
    public function destroy(Request $request): void
    {
        $ticket = $this->ticketOrFail($request);

        $validator = new Validator(['comment' => $request->param('comment')]);
        $id        = (string) $validator->uuid('comment');
        $validator->check();

        $comment = $this->comments->find($id, (string) $ticket['id'], $request->organizationId());

        if ($comment === null) {
            throw HttpException::notFound('Commentaire introuvable.');
        }

        $estAuteur  = $comment['author_id'] !== null && $comment['author_id'] === $request->userId();
        $estGardien = in_array($request->organizationRole(), ['owner', 'admin'], true);

        if (!$estAuteur && !$estGardien) {
            throw HttpException::forbidden('Seul son auteur, ou un administrateur, retire un commentaire.');
        }

        $this->comments->softDelete($id, (string) $ticket['id'], $request->organizationId());

        Response::noContent();
    }

    /**
     * Le ticket d'abord : un commentaire n'existe que sous un ticket de CET
     * espace. Un ticket d'un autre espace répond « introuvable », comme un
     * ticket qui n'existe pas.
     *
     * @return array<string, mixed>
     */
    private function ticketOrFail(Request $request): array
    {
        $validator = new Validator(['id' => $request->param('id')]);
        $id        = (string) $validator->uuid('id');
        $validator->check();

        $ticket = $this->tickets->find($id, $request->organizationId());

        if ($ticket === null) {
            throw HttpException::notFound('Ticket introuvable.');
        }

        return $ticket;
    }
}
