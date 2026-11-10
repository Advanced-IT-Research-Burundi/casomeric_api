<?php

namespace App\Jobs;

use App\Mail\PasswordResetSuccessMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the "your password was changed" confirmation in the background.
 */
class SendPasswordResetSuccessMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public int $timeout = 60;

    public function __construct(public User $user)
    {
    }

    public function handle(): void
    {
        Mail::to($this->user->email)->send(new PasswordResetSuccessMail($this->user));
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('Password changed confirmation email could not be sent after all retries.', [
            'user_id' => $this->user->id,
            'exception' => $exception->getMessage(),
        ]);
    }
}
