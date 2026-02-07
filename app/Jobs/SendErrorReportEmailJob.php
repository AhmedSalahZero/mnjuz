<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendErrorReportEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $subject;
    public string $body;
    public string $to;

    public $tries = 3;
    public $timeout = 60;

    public function __construct(string $subject, string $body, string $to = 'asalahdev5@gmail.com')
    {
        $this->subject = $subject;
        $this->body = $body;
        $this->to = $to;
        $this->onQueue('high');
    }

    public function handle(): void
    {
        try {
            $body = strlen($this->body) > 100 * 1024
                ? substr($this->body, 0, 100 * 1024) . "\n\n[... truncated ...]"
                : $this->body;
            Mail::raw($body, function ($message) {
                $message->to($this->to)
                    ->subject($this->subject);
            });
        } catch (\Throwable $e) {
            Log::channel('single')->error('SendErrorReportEmailJob failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
