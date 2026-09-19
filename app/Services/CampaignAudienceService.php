<?php

namespace App\Services;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;

/**
 * من تذهب إليه الحملة.
 *
 * مصدر واحد للسؤال، تسأله شاشة الإنشاء قبل الحفظ ويسأله المُرسِل عند
 * التنفيذ. ولو اختلف الاثنان لقَبِلَت الشاشة حملةً لا جمهور لها ثم وقفت
 * صامتة — وهو ما وقع فعلاً في الإنتاج: منشأة أنشأت سبع حملات على مجموعة
 * فارغة، فبقيت كلّها «مجدولة» بلا سجلّ واحد ولا رسالة تُنبّه.
 *
 * والانسحاب من التسويق محسوب هنا: من انسحب ليس جمهوراً، فمجموعةٌ كل من فيها
 * منسحب جمهورُها صفر ولو بدت ممتلئة.
 */
class CampaignAudienceService
{
    /**
     * @param  int|string|null  $contactGroupId  فارغ أو '0' يعني كل جهات الاتصال
     */
    public static function query(int $organizationId, $contactGroupId): Builder
    {
        if (empty($contactGroupId) || (string) $contactGroupId === '0') {
            return Contact::where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->whereNull('marketing_opted_out_at');
        }

        return Contact::whereHas('contactGroups', function ($query) use ($contactGroupId) {
            $query->where('contact_groups.id', $contactGroupId);
        })
            ->whereNull('deleted_at')
            ->whereNull('marketing_opted_out_at');
    }

    public static function count(int $organizationId, $contactGroupId): int
    {
        return self::query($organizationId, $contactGroupId)->count();
    }

    public static function isEmpty(int $organizationId, $contactGroupId): bool
    {
        return !self::query($organizationId, $contactGroupId)->exists();
    }
}
