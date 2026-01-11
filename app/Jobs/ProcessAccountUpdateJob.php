<?php

namespace App\Jobs;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessAccountUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
 	public $timeout = 30;
    public $tries = 2;

    protected $data;
    protected $organizationId;

    public function __construct($data, $organizationId)
    {
        $this->data = $data;
        $this->organizationId = $organizationId;
    }

    public function handle()
    {
        try {
            $field = $this->data['field'];
            $value = $this->data['value'] ?? null;

            if(!$value) return;

            $organization = Organization::find($this->organizationId);
            if(!$organization) return;

            $metadata = $organization->metadata 
                ? json_decode($organization->metadata, true) 
                : [];

            // ✅ تحديث حسب النوع
            switch($field) {
                case 'account_review_update':
                    $metadata['whatsapp']['account_review_status'] = $value['decision'] ?? null;
                    break;

                case 'phone_number_name_update':
                    if($value['decision'] == 'APPROVED') {
                        $metadata['whatsapp']['verified_name'] = $value['requested_verified_name'] ?? null;
                    }
                    break;

                case 'phone_number_quality_update':
                    $metadata['whatsapp']['messaging_limit_tier'] = $value['current_limit'] ?? null;
                    break;

                case 'business_capability_update':
                    $metadata['whatsapp']['max_daily_conversation_per_phone'] = $value['max_daily_conversation_per_phone'] ?? null;
                    $metadata['whatsapp']['max_phone_numbers_per_business'] = $value['max_phone_numbers_per_business'] ?? null;
                    break;
            }

            // ✅ حفظ
            $organization->metadata = json_encode($metadata);
            $organization->save();

            // ✅ مسح Cache
            Cache::forget("org_settings_{$this->organizationId}");
            Cache::forget("org_config_{$this->organizationId}");

            Log::info('Account updated successfully', [
                'organization_id' => $this->organizationId,
                'field' => $field
            ]);

        } catch (\Exception $e) {
            Log::error('ProcessAccountUpdateJob failed', [
                'organization_id' => $this->organizationId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }
}
