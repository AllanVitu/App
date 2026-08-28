<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use RuntimeException;

/**
 * Client SMTP minimal.
 *
 * L'API n'ayant aucune dépendance tierce, l'envoi est implémenté directement
 * sur le protocole (RFC 5321). Le périmètre couvre ce dont l'application a
 * besoin : STARTTLS, AUTH LOGIN, et un corps multipart texte + HTML.
 *
 * En développement, Mailpit (service « mailer ») intercepte tout : aucun
 * message ne part vers une vraie boîte.
 */
final class Mailer
{
    /** Délai maximal d'attente d'une réponse serveur (secondes). */
    private const TIMEOUT = 8;

    /** @var resource|null */
    private $socket = null;

    /**
     * @param array<string, string> $variables Remplacements {{clé}} dans le gabarit
     *
     * @throws RuntimeException si le serveur SMTP refuse le message
     */
    public function send(string $toEmail, string $toName, string $subject, string $html, string $text): void
    {
        $fromAddress = Env::get('MAIL_FROM_ADDRESS', 'no-reply@saas.local') ?? 'no-reply@saas.local';
        $fromName    = Env::get('MAIL_FROM_NAME', 'SaaS App') ?? 'SaaS App';

        // Une adresse ou un sujet contenant un retour à la ligne permettrait
        // d'injecter des en-têtes arbitraires (Bcc, Content-Type...).
        $toEmail  = $this->sanitizeHeaderValue($toEmail);
        $toName   = $this->sanitizeHeaderValue($toName);
        $subject  = $this->sanitizeHeaderValue($subject);

        if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Adresse destinataire invalide.');
        }

        $this->connect();

        try {
            $this->command('MAIL FROM:<' . $fromAddress . '>', [250]);
            $this->command('RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->command('DATA', [354]);

            $this->write($this->buildMessage($fromAddress, $fromName, $toEmail, $toName, $subject, $html, $text));
            $this->command('.', [250]);
            $this->command('QUIT', [221]);
        } finally {
            $this->disconnect();
        }
    }

    // -----------------------------------------------------------------------
    // Connexion
    // -----------------------------------------------------------------------

    private function connect(): void
    {
        $host = Env::get('MAIL_HOST', 'mailer') ?? 'mailer';
        $port = Env::int('MAIL_PORT', 1025);

        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errorCode,
            $errorMessage,
            self::TIMEOUT,
        );

        if ($socket === false) {
            throw new RuntimeException("Connexion SMTP impossible ({$host}:{$port}) : {$errorMessage}");
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, self::TIMEOUT);

        $this->expect([220]);
        $this->command('EHLO ' . $this->clientName(), [250]);

        if (strtolower(Env::get('MAIL_ENCRYPTION', 'none') ?? 'none') === 'tls') {
            $this->command('STARTTLS', [220]);

            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Négociation STARTTLS échouée.');
            }

            // Après STARTTLS, la session repart de zéro : EHLO doit être rejoué.
            $this->command('EHLO ' . $this->clientName(), [250]);
        }

        $username = Env::get('MAIL_USERNAME');
        $password = Env::get('MAIL_PASSWORD');

        if ($username !== null && $password !== null) {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($username), [334]);
            $this->command(base64_encode($password), [235]);
        }
    }

    private function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
    }

    private function clientName(): string
    {
        // Nom annoncé au serveur : jamais une valeur venue du client HTTP.
        return Env::get('MAIL_FROM_ADDRESS') !== null
            ? (explode('@', (string) Env::get('MAIL_FROM_ADDRESS'))[1] ?? 'localhost')
            : 'localhost';
    }

    // -----------------------------------------------------------------------
    // Dialogue SMTP
    // -----------------------------------------------------------------------

    /** @param list<int> $expected */
    private function command(string $command, array $expected): void
    {
        $this->write($command . "\r\n");
        $this->expect($expected);
    }

    private function write(string $data): void
    {
        if (!is_resource($this->socket) || fwrite($this->socket, $data) === false) {
            throw new RuntimeException('Écriture sur le flux SMTP impossible.');
        }
    }

    /**
     * Lit une réponse (éventuellement multiligne) et vérifie son code.
     *
     * @param list<int> $expected
     */
    private function expect(array $expected): void
    {
        $lines = [];

        while (is_resource($this->socket)) {
            $line = fgets($this->socket, 1024);

            if ($line === false) {
                throw new RuntimeException('Réponse SMTP interrompue : ' . implode(' | ', $lines));
            }

            $lines[] = rtrim($line);

            // Une réponse multiligne se termine par « 250 texte », les lignes
            // intermédiaires utilisant « 250-texte ».
            if (preg_match('/^\d{3} /', $line) === 1) {
                break;
            }
        }

        $code = (int) substr((string) end($lines), 0, 3);

        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('Réponse SMTP inattendue : ' . implode(' | ', $lines));
        }
    }

    // -----------------------------------------------------------------------
    // Construction du message
    // -----------------------------------------------------------------------

    private function buildMessage(
        string $fromAddress,
        string $fromName,
        string $toEmail,
        string $toName,
        string $subject,
        string $html,
        string $text,
    ): string {
        $boundary = 'bnd_' . bin2hex(random_bytes(12));

        $headers = [
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->clientName() . '>',
            'From: ' . $this->encodeAddress($fromName, $fromAddress),
            'To: ' . $this->encodeAddress($toName, $toEmail),
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $body = implode("\r\n", [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($text), 76, "\r\n"),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($html), 76, "\r\n"),
            '--' . $boundary . '--',
            '',
        ]);

        return implode("\r\n", $headers) . "\r\n\r\n" . $this->stuffDots($body);
    }

    /**
     * Une ligne du corps commençant par un point terminerait prématurément
     * la commande DATA : le protocole impose de la doubler (RFC 5321 §4.5.2).
     */
    private function stuffDots(string $body): string
    {
        return preg_replace('/^\./m', '..', $body) ?? $body;
    }

    private function encodeAddress(string $name, string $address): string
    {
        return $name === '' ? $address : sprintf('%s <%s>', $this->encodeHeader($name), $address);
    }

    /** Encodage MIME des en-têtes non ASCII (RFC 2047). */
    private function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function sanitizeHeaderValue(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }
}
