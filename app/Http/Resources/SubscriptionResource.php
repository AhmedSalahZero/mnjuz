<?php 

namespace App\Http\Resources;

use App\Helpers\DateTimeHelper;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $data = $this->resource->toArray();

        if (isset($data['start_date'])) {
            $startDate = DateTimeHelper::convertToOrganizationTimezone($data['start_date']);
            $data['start_date'] = DateTimeHelper::formatDate($startDate);
        }

        if (isset($data['valid_until'])) {
            $validUntil = DateTimeHelper::convertToOrganizationTimezone($data['valid_until']);
            $data['valid_until'] = DateTimeHelper::formatDate($validUntil);
        }

        return $data;
    }
}
