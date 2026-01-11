<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\DB;

class CustomEmailVerification extends Mailable
{
    use Queueable, SerializesModels;

    protected $user;
    protected $template;

    public function __construct($user)
    {
        $this->user = $user;
        $this->template = DB::table('email_templates')->where('name', 'Verify Email')->first();
    }

    public function build()
    {
        $verificationUrl = $this->verificationUrl($this->user);
        $templateContent = $this->replacePlaceholders($this->template->body, [
            'firstName' => $this->user->first_name,
            'verificationLink' => $verificationUrl,
        ]);

        return $this->subject($this->template->subject)
                    ->view('emails.custom_email_template')
                    ->with([
                        'body' => $templateContent,
                    ]);
    }

    protected function verificationUrl($user)
    {
        return URL::temporarySignedRoute(
            'verification.verify', 
            now()->addMinutes(60), 
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]
        );
    }

    protected function replacePlaceholders($content, $placeholders)
    {
        foreach ($placeholders as $key => $value) {
            $content = str_replace('{' . $key . '}', $value, $content);
        }
        return $content;
    }
}
