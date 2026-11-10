<?php

namespace App\Jobs;

use App\Mail\WelcomEmail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the welcome email in the background.
 *
 * When $token is given (account created by an admin) the email contains
 * the link to define the first password. The payload is encrypted because
 * it holds that token.
 */
class SendWelcomeMailJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public int $timeout = 60;

    public function __construct(
        public User $user,
        public ?string $token = null,
    ) {
        // The user is created inside a DB transaction: wait for the commit.
        $this->afterCommit();
    }

    public function handle(): void
    {
        Mail::to($this->user->email)->send(new WelcomEmail($this->user, $this->token));
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Welcome email could not be sent after all retries.', [
            'user_id' => $this->user->id,
            'with_password_link' => $this->token !== null,
            'exception' => $exception->getMessage(),
        ]);
    }
}
