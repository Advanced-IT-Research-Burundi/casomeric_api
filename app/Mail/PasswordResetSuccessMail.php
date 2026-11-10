<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirms to the user that their password was just changed,
 * so they can react if they did not do it themselves.
 */
class PasswordResetSuccessMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'CASOMIREC – Votre mot de passe a été modifié',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.password_reset_success',
            with: [
                'user' => $this->user,
                'loginUrl' => rtrim(config('app.frontend_url'), '/').'/login',
                'forgotUrl' => rtrim(config('app.frontend_url'), '/').'/forgot-password',
                'changedAt' => now()->format('d/m/Y à H:i'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
