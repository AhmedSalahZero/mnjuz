<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class CustomEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $subject;
    public $body;

    /**
     * إعادة المحاولة لأخطاء SMTP العابرة: SES يُغلق الاتصال الخامل في الـ worker
     * فتردّ الرسالة التالية "451 4.4.2 Timeout"، والمحاولة التالية تتّصل من جديد.
     * ورمز 4xx يعني أن الخادم لم يقبل الرسالة، فلا تصل مرّتين.
     */
    public $tries = 3;
    public $backoff = [10, 60];

    public function __construct($subject, $body)
    {
        $this->subject = $subject;
        $this->body = $body;
    }

    public function build()
    {
        return $this->subject($this->subject)
                    ->view('emails.custom_email_template')
                    ->with(['body' => $this->body]);
    }
}
