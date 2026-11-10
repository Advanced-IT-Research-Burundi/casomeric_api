<?php

namespace App\Jobs;

use App\Mail\ForgotPasswordMail;
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
 * Sends the "set / reset your password" email in the background.
 *
 * The job payload holds the plain reset token, so it is encrypted
 * in the jobs table (ShouldBeEncrypted).
 */
class SendForgotPasswordMailJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Attempts before the job is marked as failed. */
    public int $tries = 3;

    /** Seconds to wait between attempts (SMTP hiccups). */
    public array $backoff = [30, 120];

    public int $timeout = 60;

    public function __construct(
        public User $user,
        public string $token,
        public bool $isNewAccount = false,
    ) {
        // Only dispatch once the user row is committed (member creation uses a transaction).
        $this->afterCommit();
    }

    public function handle(): void
    {
        Mail::to($this->user->email)->send(
            new ForgotPasswordMail($this->user, $this->token, $this->isNewAccount)
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Password link email could not be sent after all retries.', [
            'user_id' => $this->user->id,
            'new_account' => $this->isNewAccount,
            'exception' => $exception->getMessage(),
        ]);
    }
}
