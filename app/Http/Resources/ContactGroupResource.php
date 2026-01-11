<?php 

namespace App\Http\Resources;

use App\Helpers\DateTimeHelper;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactGroupResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $data = parent::toArray($request);

        return $data;
    }
}
