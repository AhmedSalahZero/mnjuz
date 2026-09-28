<?php

namespace App\Services;

use App\Models\Contact;
use App\Services\Messaging\Channel;
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
 *
 * والقناة محسوبة كذلك: جهة اتصال تيليجرام لا رقم لها، وإرسال حملة واتساب
 * إليها يُنتج طلباً بـ to=null ترفضه Meta — فتظهر الحملة فاشلةً جزئياً بلا
 * سبب مفهوم. المجموعة الواحدة قد تجمع قنوات شتّى، فالتصفية هنا لا في نيّة
 * من أنشأ المجموعة.
 */
class CampaignAudienceService
{
    /**
     * @param  int|string|null  $contactGroupId  فارغ أو '0' يعني كل جهات الاتصال
     * @param  string           $channel         قناة الحملة — الافتراضي واتساب
     */
    public static function query(int $organizationId, $contactGroupId, string $channel = Channel::DEFAULT): Builder
    {
        $base = (empty($contactGroupId) || (string) $contactGroupId === '0')
            ? Contact::where('organization_id', $organizationId)
            : Contact::whereHas('contactGroups', function ($query) use ($contactGroupId) {
                $query->where('contact_groups.id', $contactGroupId);
            });

        return $base
            ->where('contacts.channel', $channel)
            ->whereNull('deleted_at')
            ->whereNull('marketing_opted_out_at');
    }

    public static function count(int $organizationId, $contactGroupId, string $channel = Channel::DEFAULT): int
    {
        return self::query($organizationId, $contactGroupId, $channel)->count();
    }

    public static function isEmpty(int $organizationId, $contactGroupId, string $channel = Channel::DEFAULT): bool
    {
        return !self::query($organizationId, $contactGroupId, $channel)->exists();
    }
}
