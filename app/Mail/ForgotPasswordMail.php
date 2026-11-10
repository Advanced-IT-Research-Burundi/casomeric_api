<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sends the link that lets a user choose a password.
 *
 * Used for "Mot de passe oublié" and for a new member account
 * ($isNewAccount = true), which needs a first password.
 *
 * Sent synchronously (no ShouldQueue) so the controller can tell
 * the user right away if the email could not be sent.
 */
class ForgotPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $resetUrl;

    public int $expireMinutes;

    public function __construct(
        public User $user,
        string $token,
        public bool $isNewAccount = false,
    ) {
        $this->resetUrl = self::buildResetUrl($user, $token);
        $this->expireMinutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
    }

    /**
     * Link to the Vue page that sets the new password.
     */
    public static function buildResetUrl(User $user, string $token): string
    {
        return rtrim(config('app.frontend_url'), '/')
            .'/reset-password?token='.urlencode($token)
            .'&email='.urlencode($user->getEmailForPasswordReset());
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isNewAccount
                ? 'CASOMIREC – Activez votre compte'
                : 'CASOMIREC – Réinitialisation de votre mot de passe',
        );
    }

    public function content(): Content
    {
        $hours = intdiv($this->expireMinutes, 60);

        return new Content(
            view: 'emails.auth.forgot_password',
            with: [
                'user' => $this->user,
                'resetUrl' => $this->resetUrl,
                'isNewAccount' => $this->isNewAccount,
                'validity' => $hours >= 1 ? $hours.' heure(s)' : $this->expireMinutes.' minutes',
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
