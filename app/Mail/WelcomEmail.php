<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Welcome email for a new CASOMIREC account.
 *
 * - With a $token (account created by an admin): contains the link to
 *   define the first password, then the login link.
 * - Without a token (user registered and chose a password): login link only.
 *
 * Sent in the background by App\Jobs\SendWelcomeMailJob (not ShouldQueue
 * itself, so the token stays inside the encrypted job payload).
 */
class WelcomEmail extends Mailable
{
    use Queueable, SerializesModels;

    public ?string $setPasswordUrl;

    public string $loginUrl;

    public int $expireMinutes;

    public function __construct(public User $user, ?string $token = null)
    {
        $this->setPasswordUrl = $token ? ForgotPasswordMail::buildResetUrl($user, $token) : null;
        $this->loginUrl = rtrim(config('app.frontend_url'), '/').'/login';
        $this->expireMinutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Bienvenue à CASOMIREC',
        );
    }

    public function content(): Content
    {
        $hours = intdiv($this->expireMinutes, 60);

        return new Content(
            view: 'emails.welcome',
            with: [
                'user' => $this->user,
                'setPasswordUrl' => $this->setPasswordUrl,
                'loginUrl' => $this->loginUrl,
                'forgotUrl' => rtrim(config('app.frontend_url'), '/').'/forgot-password',
                'validity' => $hours >= 1 ? $hours.' heure(s)' : $this->expireMinutes.' minutes',
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
