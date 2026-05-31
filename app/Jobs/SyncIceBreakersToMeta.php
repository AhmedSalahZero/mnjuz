<?php

namespace App\Jobs;

use App\Models\IceBreaker;
use App\Models\Organization;
use App\Models\WhatsappCommand;
use App\Services\WhatsappService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncIceBreakersToMeta implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $organizationId
    ) {
    }

    public function handle(): void
    {
        $organization = Organization::find($this->organizationId);
        if (!$organization) {
            return;
        }

        $config = $organization->metadata ? json_decode($organization->metadata, true) : [];
        if (!isset($config['whatsapp'])) {
            return;
        }

        $whatsapp = $config['whatsapp'];
        $accessToken = $whatsapp['access_token'] ?? null;
        $phoneNumberId = $whatsapp['phone_number_id'] ?? null;

        if (!$accessToken || !$phoneNumberId) {
            $this->storeSyncStatus($organization, $config, 'failed', __('WhatsApp integration is not configured.'));
            return;
        }

        $prompts = IceBreaker::where('organization_id', $this->organizationId)
            ->orderBy('sort_order')
            ->pluck('text')
            ->values()
            ->all();

        $commands = WhatsappCommand::where('organization_id', $this->organizationId)
            ->orderBy('sort_order')
            ->get(['command_name', 'command_description'])
            ->map(fn ($command) => [
                'command_name' => $command->command_name,
                'command_description' => $command->command_description,
            ])
            ->values()
            ->all();

        $whatsappService = new WhatsappService(
            $accessToken,
            config('graph.api_version'),
            $whatsapp['app_id'] ?? null,
            $phoneNumberId,
            $whatsapp['waba_id'] ?? null,
            $this->organizationId
        );

        try {
            $response = $whatsappService->syncIceBreakers($prompts, $commands);

            if ($response->success ?? false) {
                $this->storeSyncStatus($organization, $config, 'success');
            } else {
                $message = $response->data->error->message ?? __('Failed to sync ice breakers with Meta.');
                $this->storeSyncStatus($organization, $config, 'failed', $message);
                Log::warning('Ice breakers Meta sync failed', [
                    'organization_id' => $this->organizationId,
                    'error' => $message,
                ]);
            }
        } catch (\Throwable $e) {
            $this->storeSyncStatus($organization, $config, 'failed', $e->getMessage());
            Log::warning('Ice breakers Meta sync exception', [
                'organization_id' => $this->organizationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function storeSyncStatus(Organization $organization, array $config, string $status, ?string $message = null): void
    {
        $config['whatsapp']['ice_breakers_sync'] = [
            'status' => $status,
            'message' => $message,
            'synced_at' => now()->toIso8601String(),
        ];

        $organization->metadata = json_encode($config);
        $organization->save();
    }
}
