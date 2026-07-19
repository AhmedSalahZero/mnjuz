<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Services\WhatsappService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTextMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $organizationId;
    public string $contactUuid;
    public string $messageContent;
    public ?int $userId;
    public string $type;
    public ?array $buttons;
    public ?array $header;
    public ?string $footer;
    public ?string $buttonLabel;
    public ?string $messageUUID;
    public mixed $tempMessageId;

    public function __construct(
        int $organizationId,
        string $contactUuid,
        string $messageContent,
        ?int $userId = null,
        string $type = 'text',
        ?array $buttons = null,
        ?array $header = null,
        ?string $footer = null,
        ?string $buttonLabel = null,
        ?string $messageUUID = null,
        mixed $tempMessageId = null
    ) {
        $this->organizationId = $organizationId;
        $this->contactUuid = $contactUuid;
        $this->messageContent = $messageContent;
        $this->userId = $userId;
        $this->type = $type;
        $this->buttons = $buttons;
        $this->header = $header;
        $this->footer = $footer;
        $this->buttonLabel = $buttonLabel;
        $this->messageUUID = $messageUUID;
        $this->tempMessageId = $tempMessageId;
    }

    public function handle(): void
    {
        $organization = Organization::find($this->organizationId);
        if (!$organization) {
            return;
        }

        $config = $organization->metadata ? json_decode($organization->metadata, true) : [];
        $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = config('graph.api_version');
        $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        $whatsappService = new WhatsappService(
            $accessToken,
            $apiVersion,
            $appId,
            $phoneNumberId,
            $wabaId,
            $this->organizationId
        );

        $whatsappService->executeSendMessage(
            $this->contactUuid,
            $this->messageContent,
            $this->userId,
            $this->type,
            $this->buttons ?? [],
            $this->header ?? [],
            $this->footer,
            $this->buttonLabel,
            $this->messageUUID,
            $this->tempMessageId
        );
    }
}
