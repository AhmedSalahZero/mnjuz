<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\SyncIceBreakersRequest;
use App\Http\Requests\SyncWhatsappCommandsRequest;
use App\Jobs\SyncIceBreakersToMeta;
use App\Models\Addon;
use App\Models\IceBreaker;
use App\Models\Organization;
use App\Models\WhatsappCommand;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class IceBreakerController extends BaseController
{
    private function getCurrentOrganizationId(): int
    {
        return (int) session()->get('current_organization');
    }

    private function isFeatureEnabled(): bool
    {
        return SubscriptionService::isSubscriptionFeatureEnabled(
            (string) $this->getCurrentOrganizationId(),
            'ice_breakers'
        );
    }

    public function index(Request $request)
    {
        if (!$this->isFeatureEnabled()) {
            return redirect('/settings')->with('status', [
                'type' => 'info',
                'message' => __('Ice Breakers are not available in your plan.'),
            ]);
        }

        $organizationId = $this->getCurrentOrganizationId();
        $organization = Organization::find($organizationId);
        $metadata = $organization->metadata ? json_decode($organization->metadata, true) : [];
        $whatsapp = $metadata['whatsapp'] ?? [];

        $iceBreakers = IceBreaker::where('organization_id', $organizationId)
            ->orderBy('sort_order')
            ->get(['id', 'text', 'sort_order']);

        $commands = WhatsappCommand::where('organization_id', $organizationId)
            ->orderBy('sort_order')
            ->get(['id', 'command_name', 'command_description', 'sort_order']);

        return Inertia::render('User/Settings/Conversations', [
            'title' => __('Settings'),
            'modules' => Addon::get(),
            'iceBreakers' => $iceBreakers,
            'commands' => $commands,
            'whatsappProfile' => [
                'verified_name' => $whatsapp['verified_name'] ?? '',
                'display_phone_number' => $whatsapp['display_phone_number'] ?? '',
            ],
            'syncStatus' => $whatsapp['ice_breakers_sync'] ?? null,
        ]);
    }

    public function sync(SyncIceBreakersRequest $request)
    {
        if ($response = $this->abortIfDemo()) {
            return $response;
        }

        if (!$this->isFeatureEnabled()) {
            return response()->json([
                'success' => false,
                'message' => __('Ice Breakers are not available in your plan.'),
            ], 403);
        }

        $organizationId = $this->getCurrentOrganizationId();
        $items = $request->input('items', []);

        DB::transaction(function () use ($organizationId, $items) {
            $keptIds = [];

            foreach ($items as $index => $item) {
                $sortOrder = $item['sort_order'] ?? $index;

                if (!empty($item['id'])) {
                    $iceBreaker = IceBreaker::where('id', $item['id'])
                        ->where('organization_id', $organizationId)
                        ->first();

                    if ($iceBreaker) {
                        $iceBreaker->text = $item['text'];
                        $iceBreaker->sort_order = $sortOrder;
                        $iceBreaker->save();
                        $keptIds[] = $iceBreaker->id;
                        continue;
                    }
                }

                $created = IceBreaker::create([
                    'organization_id' => $organizationId,
                    'text' => $item['text'],
                    'sort_order' => $sortOrder,
                ]);
                $keptIds[] = $created->id;
            }

            IceBreaker::where('organization_id', $organizationId)
                ->whereNotIn('id', $keptIds)
                ->delete();
        });

        SyncIceBreakersToMeta::dispatch($organizationId);

        $iceBreakers = IceBreaker::where('organization_id', $organizationId)
            ->orderBy('sort_order')
            ->get(['id', 'text', 'sort_order']);

        return response()->json([
            'success' => true,
            'message' => __('Ice breakers saved successfully'),
            'data' => $iceBreakers,
        ]);
    }

    public function syncCommands(SyncWhatsappCommandsRequest $request)
    {
        if ($response = $this->abortIfDemo()) {
            return $response;
        }

        if (!$this->isFeatureEnabled()) {
            return response()->json([
                'success' => false,
                'message' => __('Ice Breakers are not available in your plan.'),
            ], 403);
        }

        $organizationId = $this->getCurrentOrganizationId();
        $items = $request->input('items', []);

        DB::transaction(function () use ($organizationId, $items) {
            $keptIds = [];

            foreach ($items as $index => $item) {
                $sortOrder = $item['sort_order'] ?? $index;

                if (!empty($item['id'])) {
                    $command = WhatsappCommand::where('id', $item['id'])
                        ->where('organization_id', $organizationId)
                        ->first();

                    if ($command) {
                        $command->command_name = $item['command_name'];
                        $command->command_description = $item['command_description'];
                        $command->sort_order = $sortOrder;
                        $command->save();
                        $keptIds[] = $command->id;
                        continue;
                    }
                }

                $created = WhatsappCommand::create([
                    'organization_id' => $organizationId,
                    'command_name' => $item['command_name'],
                    'command_description' => $item['command_description'],
                    'sort_order' => $sortOrder,
                ]);
                $keptIds[] = $created->id;
            }

            WhatsappCommand::where('organization_id', $organizationId)
                ->whereNotIn('id', $keptIds)
                ->delete();
        });

        SyncIceBreakersToMeta::dispatch($organizationId);

        $commands = WhatsappCommand::where('organization_id', $organizationId)
            ->orderBy('sort_order')
            ->get(['id', 'command_name', 'command_description', 'sort_order']);

        return response()->json([
            'success' => true,
            'message' => __('Commands saved successfully'),
            'data' => $commands,
        ]);
    }

    public function checkMeta(Request $request)
    {
        if (!$this->isFeatureEnabled()) {
            return response()->json(['success' => false, 'message' => 'Feature not enabled'], 403);
        }

        $organizationId = $this->getCurrentOrganizationId();
        $organization = Organization::find($organizationId);
        $config = $organization->metadata ? json_decode($organization->metadata, true) : [];
        $whatsapp = $config['whatsapp'] ?? [];

        $service = new \App\Services\WhatsappService(
            $whatsapp['access_token'] ?? null,
            config('graph.api_version'),
            $whatsapp['app_id'] ?? null,
            $whatsapp['phone_number_id'] ?? null,
            $whatsapp['waba_id'] ?? null,
            $organizationId
        );

        $result = $service->getConversationalAutomation();

        return response()->json([
            'success' => true,
            'meta_data' => $result,
        ]);
    }

    protected function abortIfDemo()
    {
        $organizationId = session()->get('current_organization');

        if (app()->environment('demo') && $organizationId == 1) {
            return response()->json([
                'success' => false,
                'message' => __('You cannot perform this action using the demo account. To test this feature, please create your own account.'),
            ], 403);
        }

        return null;
    }
}
