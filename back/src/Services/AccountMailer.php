<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Core\Request;
use Throwable;

/**
 * Messages transactionnels liés au compte.
 *
 * Deux principes :
 *  - le corps HTML est intégralement échappé (htmlspecialchars) : un nom
 *    d'utilisateur ne peut pas injecter de balises dans le message ;
 *  - un échec d'envoi ne fait JAMAIS échouer l'action métier. Une inscription
 *    réussie ne doit pas être annulée parce que le SMTP est indisponible :
 *    l'erreur est journalisée et l'utilisateur peut redemander l'e-mail.
 */
final class AccountMailer
{
    public function __construct(
        private readonly Mailer $mailer = new Mailer(),
        private readonly UserTokenService $tokens = new UserTokenService(),
    ) {
    }

    /**
     * Émet un jeton de confirmation et envoie le lien correspondant.
     * Mutualisé entre l'inscription et le renvoi manuel.
     *
     * @param array<string, mixed> $user
     */
    public function sendVerificationLink(array $user, Request $request): bool
    {
        $token = $this->tokens->issue(
            (string) $user['id'],
            UserTokenService::TYPE_EMAIL_VERIFICATION,
            $request,
        );

        return $this->sendEmailVerification(
            (string) $user['email'],
            (string) $user['full_name'],
            $token,
        );
    }

    /**
     * Émet un jeton de réinitialisation et envoie le lien correspondant.
     *
     * @param array<string, mixed> $user
     */
    public function sendResetLink(array $user, Request $request): bool
    {
        $token = $this->tokens->issue(
            (string) $user['id'],
            UserTokenService::TYPE_PASSWORD_RESET,
            $request,
        );

        return $this->sendPasswordReset(
            (string) $user['email'],
            (string) $user['full_name'],
            $token,
        );
    }

    /**
     * Lien de confirmation d'adresse e-mail.
     */
    public function sendEmailVerification(string $email, string $name, string $token): bool
    {
        $link = $this->frontendUrl('/verification-email', $token);

        return $this->deliver(
            $email,
            $name,
            'Confirmez votre adresse e-mail',
            $this->layout(
                'Confirmez votre adresse',
                $name,
                'Bienvenue ! Il ne reste qu\'une étape : confirmer cette adresse e-mail pour sécuriser votre compte.',
                'Confirmer mon adresse',
                $link,
                'Ce lien est valable 24 heures. Si vous n\'êtes pas à l\'origine de cette inscription, ignorez ce message.',
            ),
            "Bonjour {$name},\n\nConfirmez votre adresse e-mail en ouvrant ce lien :\n{$link}\n\n"
            . "Ce lien est valable 24 heures.\n",
        );
    }

    /**
     * Lien de réinitialisation de mot de passe.
     */
    public function sendPasswordReset(string $email, string $name, string $token): bool
    {
        $link = $this->frontendUrl('/reinitialisation', $token);

        return $this->deliver(
            $email,
            $name,
            'Réinitialisation de votre mot de passe',
            $this->layout(
                'Réinitialisation du mot de passe',
                $name,
                'Vous avez demandé à réinitialiser votre mot de passe. Ce lien vous permet d\'en choisir un nouveau.',
                'Choisir un nouveau mot de passe',
                $link,
                'Ce lien expire dans 1 heure et ne peut servir qu\'une fois. '
                . 'Si vous n\'êtes pas à l\'origine de cette demande, aucune action n\'est nécessaire : '
                . 'votre mot de passe actuel reste valable.',
            ),
            "Bonjour {$name},\n\nRéinitialisez votre mot de passe avec ce lien :\n{$link}\n\n"
            . "Ce lien expire dans 1 heure. Si vous n'êtes pas à l'origine de la demande, ignorez ce message.\n",
        );
    }

    /**
     * Avertissement après un changement de mot de passe réussi.
     * C'est le signal qui permet à un utilisateur de réagir si le changement
     * ne vient pas de lui.
     */
    public function sendPasswordChangedNotice(string $email, string $name): bool
    {
        return $this->deliver(
            $email,
            $name,
            'Votre mot de passe a été modifié',
            $this->layout(
                'Mot de passe modifié',
                $name,
                'Le mot de passe de votre compte vient d\'être modifié et toutes vos sessions ont été déconnectées.',
                null,
                null,
                'Si vous n\'êtes pas à l\'origine de ce changement, réinitialisez immédiatement votre mot de passe '
                . 'et contactez le support.',
            ),
            "Bonjour {$name},\n\nLe mot de passe de votre compte vient d'être modifié.\n"
            . "Si vous n'êtes pas à l'origine de ce changement, réinitialisez-le immédiatement.\n",
        );
    }

    /**
     * @return bool false si l'envoi a échoué (journalisé, jamais propagé)
     */
    private function deliver(string $email, string $name, string $subject, string $html, string $text): bool
    {
        try {
            $this->mailer->send($email, $name, $subject, $html, $text);

            return true;
        } catch (Throwable $e) {
            error_log('[Mailer] Envoi impossible à ' . $email . ' : ' . $e->getMessage());

            return false;
        }
    }

    private function frontendUrl(string $path, string $token): string
    {
        $base = rtrim(Env::get('APP_FRONTEND_URL', 'http://localhost:5173') ?? '', '/');

        return $base . $path . '?token=' . urlencode($token);
    }

    /**
     * Gabarit HTML commun. Tout le contenu variable passe par
     * htmlspecialchars() : ni le nom, ni le lien ne peuvent casser le balisage.
     */
    private function layout(
        string $title,
        string $name,
        string $intro,
        ?string $buttonLabel,
        ?string $buttonUrl,
        string $footer,
    ): string {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $button = '';

        if ($buttonLabel !== null && $buttonUrl !== null) {
            $button = '
              <p style="margin:32px 0;text-align:center">
                <a href="' . $e($buttonUrl) . '"
                   style="background:#243dec;color:#ffffff;text-decoration:none;padding:12px 24px;
                          border-radius:8px;display:inline-block;font-weight:600">'
                . $e($buttonLabel) . '</a>
              </p>
              <p style="font-size:13px;color:#64748b;word-break:break-all">
                Si le bouton ne fonctionne pas, copiez ce lien :<br>' . $e($buttonUrl) . '
              </p>';
        }

        return '<!doctype html>
<html lang="fr"><head><meta charset="utf-8"></head>
<body style="margin:0;padding:24px;background:#f1f5f9;font-family:system-ui,-apple-system,Segoe UI,sans-serif">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td align="center">
      <table role="presentation" width="100%" style="max-width:540px;background:#ffffff;border-radius:12px;padding:32px">
        <tr><td>
          <p style="margin:0 0 24px;font-weight:700;font-size:18px;color:#243dec">SaaS App</p>
          <h1 style="margin:0 0 16px;font-size:20px;color:#0f172a">' . $e($title) . '</h1>
          <p style="margin:0 0 8px;color:#334155">Bonjour ' . $e($name) . ',</p>
          <p style="margin:0;color:#334155;line-height:1.6">' . $e($intro) . '</p>
          ' . $button . '
          <hr style="border:none;border-top:1px solid #e2e8f0;margin:28px 0">
          <p style="margin:0;font-size:13px;color:#64748b;line-height:1.6">' . $e($footer) . '</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>';
    }
}
