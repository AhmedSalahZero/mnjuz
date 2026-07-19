<?php

namespace App\Jobs;

use App\Events\NewChatEvent;
use App\Helpers\ChatMetadataHelper;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\Contact;
use App\Services\PhoneService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Coexistence: mirrors a message the business sent from the WhatsApp Business App
 * (delivered via the smb_message_echoes webhook) as an outbound chat so agents
 * see the full conversation in Monjz Chat.
 */
class ProcessMessageEchoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 3;
    public $backoff = [1, 3, 5];

    protected $echo;
    protected $value;
    protected $organizationId;

    public function __construct($echo, $value, $organizationId)
    {
        $this->echo = $echo;
        $this->value = $value;
        $this->organizationId = $organizationId;
    }

    public function handle()
    {
        try {
            $recipient = $this->echo['to'] ?? null;
            if (!$recipient) {
                Log::warning('ProcessMessageEchoJob: echo missing "to"', [
                    'organization_id' => $this->organizationId,
                    'echo_id' => $this->echo['id'] ?? null,
                ]);
                return;
            }

            [$contact, $isNewContact] = $this->getOrCreateContact($recipient);

            $chat = $this->createChat($contact);
            if (!$chat) {
                return;
            }

            $this->createChatLog($contact->id, $chat->id);

            if ($this->hasMedia()) {
                ProcessMediaDownloadJob::dispatch(
                    $chat->id,
                    $this->echo,
                    $this->organizationId,
                    $isNewContact,
                    $contact->uuid
                )->onQueue('media');
            } else {
                event(new NewChatEvent(
                    $this->formatChatForEvent($chat, $isNewContact, $contact->uuid),
                    $this->organizationId,
                    $isNewContact,
                    false,
                    false
                ));
            }
        } catch (\Exception $e) {
            Log::error('ProcessMessageEchoJob failed', [
                'organization_id' => $this->organizationId,
                'echo_id' => $this->echo['id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function getOrCreateContact(string $recipient): array
    {
        $phone = PhoneService::getE164Format('+' . ltrim($recipient, '+'));

        try {
            $contact = Contact::firstOrCreate(
                [
                    'organization_id' => $this->organizationId,
                    'phone' => $phone,
                ],
                [
                    'first_name' => null,
                    'last_name' => null,
                    'email' => null,
                    'created_by' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                $contact = Contact::where('organization_id', $this->organizationId)
                    ->where('phone', $phone)
                    ->firstOrFail();
            } else {
                throw $e;
            }
        }

        return [$contact, $contact->wasRecentlyCreated];
    }

    private function createChat($contact)
    {
        $wamId = $this->echo['id'] ?? null;

        if ($wamId && Cache::has("wam_processed_{$wamId}")) {
            return null;
        }

        try {
            $chat = Chat::create([
                'organization_id' => $this->organizationId,
                'wam_id' => $wamId,
                'contact_id' => $contact->id,
                'type' => 'outbound',
                'metadata' => json_encode(ChatMetadataHelper::minimalPayloadForStorage($this->echo)),
                'created_at' => now(),
                'status' => 'sent',
                'is_read' => 1,
            ]);

            if ($wamId) {
                Cache::put("wam_processed_{$wamId}", true, 3600);
            }

            return $chat;
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                if ($wamId) {
                    Cache::put("wam_processed_{$wamId}", true, 3600);
                }
                return null;
            }
            throw $e;
        }
    }

    private function hasMedia(): bool
    {
        return in_array($this->echo['type'] ?? null, [
            'image', 'video', 'audio', 'document', 'sticker',
        ]);
    }

    private function createChatLog($contactId, $chatId): void
    {
        ChatLog::insertGetId([
            'contact_id' => $contactId,
            'entity_type' => 'chat',
            'entity_id' => $chatId,
            'created_at' => now(),
        ]);
    }

    private function formatChatForEvent($chat, bool $isNewContact = false, $contactUuid = null)
    {
        $chatLog = ChatLog::where('entity_id', $chat->id)
            ->where('entity_type', 'chat')
            ->first();

        return [[
            'is_new_contact' => $isNewContact,
            'contact_uuid' => $contactUuid,
            'type' => 'chat',
            'value' => $chatLog->relatedEntities ?? $chat,
        ]];
    }
}
