<?php

namespace App\Http\Resources;

use App\Helpers\DateTimeHelper;
use App\Services\Messaging\AutomationChannels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AutoReplyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        // Convert updated_at to the organization's timezone and format it
        $updatedAt = DateTimeHelper::convertToOrganizationTimezone($this->updated_at);
        $data['updated_at'] = DateTimeHelper::formatDate($updatedAt);

        // ما يعرضه الجدول: أسماء القنوات مترجمة، وnull (كل القنوات) قائمةً كاملة.
        $data['channels'] = AutomationChannels::effective($this->channels);
        $data['channel_labels'] = array_map([AutomationChannels::class, 'label'], $data['channels']);

        return $data;
    }
}
