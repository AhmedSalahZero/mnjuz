<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Services\PhoneService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Coexistence: imports/updates contacts synced from the WhatsApp Business App
 * (delivered via the smb_app_state_sync webhook). Phase 1 handles "add" (create
 * or update the contact); "remove" is logged only (no deletion).
 */
class ProcessContactSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 3;
    public $backoff = [1, 3, 5];

    protected $stateSync;
    protected $organizationId;

    public function __construct(array $stateSync, $organizationId)
    {
        $this->stateSync = $stateSync;
        $this->organizationId = $organizationId;
    }

    public function handle()
    {
        foreach ($this->stateSync as $entry) {
            if (($entry['type'] ?? null) !== 'contact') {
                continue;
            }

            $contactData = $entry['contact'] ?? [];
            $action = $entry['action'] ?? 'add';
            $phoneRaw = $contactData['phone_number'] ?? null;

            if (!$phoneRaw) {
                continue;
            }

            if ($action === 'remove') {
                // Phase 1: do not delete synced contacts, just record the event.
                Log::info('Coexistence contact removal received (not applied in Phase 1)', [
                    'organization_id' => $this->organizationId,
                ]);
                continue;
            }

            $this->upsertContact($contactData, $phoneRaw);
        }
    }

    private function upsertContact(array $contactData, string $phoneRaw): void
    {
        $phone = PhoneService::getE164Format('+' . ltrim($phoneRaw, '+'));
        $displayName = $contactData['full_name'] ?? ($contactData['first_name'] ?? null);

        try {
            $contact = Contact::firstOrCreate(
                [
                    'organization_id' => $this->organizationId,
                    'phone' => $phone,
                ],
                [
                    'first_name' => $displayName,
                    'last_name' => null,
                    'email' => null,
                    'created_by' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            if (!$contact->wasRecentlyCreated && $displayName && $contact->first_name === null) {
                $contact->update(['first_name' => $displayName]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] !== 1062) {
                throw $e;
            }
        }
    }
}
