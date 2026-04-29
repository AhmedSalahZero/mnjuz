<?php

namespace App\Jobs;

use App\Jobs\SendUserVerificationEmailJob;
use App\Models\UserVerification;
use App\Services\UserVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendUserVerificationWhatsappJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $verificationId,
        public string $code
    ) {
    }

    public function handle(UserVerificationService $verificationService): void
    {
        $verification = UserVerification::with('user')->find($this->verificationId);
        if (!$verification || !$verification->user || $verification->verified_at !== null) {
            return;
        }

        try {
            $verificationService->sendWhatsappCode($verification->user, $this->code);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp verification sending failed, attempting email fallback.', [
                'verification_id' => $verification->id,
                'user_id' => $verification->user->id,
                'error' => $e->getMessage(),
            ]);

            if (!empty($verification->user->email)) {
                $verification->update(['method' => 'email']);
                SendUserVerificationEmailJob::dispatch($verification->id, $this->code, 10);
                return;
            }

            throw $e;
        }
    }
}
