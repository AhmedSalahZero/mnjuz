<?php

namespace App\Http\Resources;

use App\Helpers\DateTimeHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageResource extends JsonResource
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
        $updatedAt = DateTimeHelper::convertToCompanyTimezone($this->updated_at);
        $data['updated_at'] = DateTimeHelper::formatDateWithoutHours($updatedAt);

        return $data;
    }
}
