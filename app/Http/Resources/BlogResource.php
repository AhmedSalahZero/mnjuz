<?php

namespace App\Http\Resources;

use App\Helpers\DateTimeHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        if(isset($this->updated_at)){
            $data['updated_at'] = DateTimeHelper::formatDate($this->updated_at);
        }
        if(isset($this->publish_date)){
            $data['publish_date'] = DateTimeHelper::formatDate($this->publish_date);
        }
        if(isset($this->created_at)){
            $data['created_at'] = DateTimeHelper::formatDate($this->created_at);
        }

        return $data;
    }
}
