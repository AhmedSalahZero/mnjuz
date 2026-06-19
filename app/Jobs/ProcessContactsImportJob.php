<?php

namespace App\Jobs;

use App\Imports\ContactsImport;
use App\Services\ContactImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ProcessContactsImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;
    public $tries = 1;

    public function __construct(
        public int $organizationId,
        public int $userId,
        public string $filePath,
    ) {}

    public function handle(): void
    {
        @set_time_limit(0);

        ContactImportService::putStatus($this->organizationId, $this->userId, [
            'state' => 'processing',
            'started_at' => now()->toIso8601String(),
        ]);

        $absolutePath = Storage::disk('local')->path($this->filePath);

        try {
            if (!is_readable($absolutePath)) {
                throw new \RuntimeException('Import file is missing or not readable.');
            }

            $import = new ContactsImport($this->organizationId, $this->userId);
            Excel::import($import, $absolutePath);

            ContactImportService::putStatus($this->organizationId, $this->userId, [
                'state' => 'complete',
                'finished_at' => now()->toIso8601String(),
                'status' => ContactImportService::buildFlashStatus($import),
            ]);
        } catch (\Throwable $e) {
            Log::error('ProcessContactsImportJob failed', [
                'organization_id' => $this->organizationId,
                'user_id' => $this->userId,
                'file' => $this->filePath,
                'error' => $e->getMessage(),
            ]);

            ContactImportService::putStatus($this->organizationId, $this->userId, [
                'state' => 'failed',
                'finished_at' => now()->toIso8601String(),
                'status' => [
                    'type' => 'error',
                    'message' => __('Contact import failed. Please try again with a smaller file or contact support.'),
                ],
            ]);
        } finally {
            Storage::disk('local')->delete($this->filePath);
        }
    }
}
