<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetLinkedDevicesMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $resetUrl,
        public string $logoUrl,
        public string $companyName
    ) {}

    public function build(): self
    {
        return $this->subject(__('Reset linked devices email subject', ['app' => $this->companyName]))
            ->view('emails.reset_linked_devices')
            ->with([
                'resetUrl' => $this->resetUrl,
                'logoUrl' => $this->logoUrl,
                'companyName' => $this->companyName,
            ]);
    }
}
