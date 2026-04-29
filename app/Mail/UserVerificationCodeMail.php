<?php

namespace App\Mail;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class UserVerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
        public int $expiresInMinutes
    ) {
    }

    public function build(): self
    {
        $companyName = (string) (Setting::where('key', 'company_name')->value('value') ?? config('app.name'));
        $logo = Setting::where('key', 'logo')->value('value');
        $logoUrl = $logo ? url('/media/' . ltrim($logo, '/')) : null;

        return $this->subject(__('Your verification code'))
            ->view('emails.user_verification_code')
            ->with([
                'user' => $this->user,
                'code' => $this->code,
                'expiresInMinutes' => $this->expiresInMinutes,
                'companyName' => $companyName,
                'logoUrl' => $logoUrl,
            ]);
    }
}
