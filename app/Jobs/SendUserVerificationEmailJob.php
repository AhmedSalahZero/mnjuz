<?php

namespace App\Jobs;

use App\Mail\UserVerificationCodeMail;
use App\Models\UserVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendUserVerificationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $verificationId,
        public string $code,
        public int $expiresInMinutes
    ) {
    }

    public function handle(): void
    {
        $verification = UserVerification::with('user')->find($this->verificationId);
        if (!$verification || !$verification->user || $verification->verified_at !== null) {
            return;
        }

        Mail::to($verification->user->email)->send(
            new UserVerificationCodeMail($verification->user, $this->code, $this->expiresInMinutes)
        );
    }
}
