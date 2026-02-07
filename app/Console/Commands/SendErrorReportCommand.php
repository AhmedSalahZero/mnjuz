<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendErrorReportCommand extends Command
{
    protected $signature = 'send-error-report {payload_file : path to JSON file with subject, body, to}';
    protected $description = 'Send error report email (used by Exception Handler, runs in separate process)';

    public function handle(): int
    {
        $path = $this->argument('payload_file');
        if (!is_readable($path)) {
            $this->error('Payload file not found or not readable: ' . $path);
            $this->line('This command is run by the Exception Handler with a temp file. To test manually:');
            $this->line('  echo \'{"subject":"Test","body":"Body","to":"asalahdev5@gmail.com"}\' > /tmp/test-err.json');
            $this->line('  php artisan send-error-report /tmp/test-err.json');
            return 1;
        }

        $data = json_decode(file_get_contents($path), true);
        @unlink($path);

        if (empty($data['subject']) || !isset($data['body']) || empty($data['to'])) {
            $this->error('Invalid payload.');
            return 1;
        }

        try {
            $body = strlen($data['body']) > 100 * 1024
                ? substr($data['body'], 0, 100 * 1024) . "\n\n[... truncated ...]"
                : $data['body'];

            Mail::raw($body, function ($message) use ($data) {
                $message->to($data['to'])
                    ->subject($data['subject']);
            });
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }

        return 0;
    }
}
