<?php

namespace App\Http\Resources;

use App\Helpers\DateTimeHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        $data['attempts'] = $this->whenLoaded('attempts', function () {
            return $this->attempts->map(function ($attempt) {
                return [
                    'id' => $attempt->id,
                    'attempt_number' => $attempt->attempt_number,
                    'channel' => $attempt->channel,
                    'is_retry' => (bool) $attempt->is_retry,
                    'status' => $attempt->status,
                    'failure_reason' => $attempt->failure_reason,
                    'executed_at' => $attempt->executed_at,
                    'response_metadata' => $attempt->response_metadata,
                ];
            })->values();
        }, []);

        return $data;
    }
}
