<?php

namespace App\Http\Resources;

use App\Helpers\DateTimeHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamResource extends JsonResource
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

        // تاريخ الحذف لمبدّل «المحذوفون». يبقى null للأعضاء النشطين، فالواجهة
        // تعرض العمود عند الحاجة فقط.
        $data['deleted_at'] = $this->deleted_at
            ? DateTimeHelper::formatDate(DateTimeHelper::convertToOrganizationTimezone($this->deleted_at))
            : null;

        // هل حساب العضو نفسه محذوف أيضاً؟ يميّز «أُزيل من الفريق» عن «حذف حسابه».
        $data['user_deleted'] = (bool) ($this->user?->deleted_at);

        return $data;
    }
}
