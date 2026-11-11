<?php

namespace App\Mail;

use App\Models\Assistance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the member that the approved assistance has been paid out (versée).
 */
class AssistanceVersee extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Assistance $assistance)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "CASOMIREC – Votre assistance n° {$this->assistance->id} a été versée",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.assistance.versee',
            with: [
                'assistance' => $this->assistance,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
