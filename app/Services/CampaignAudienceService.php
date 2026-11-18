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
        if ($channel === Channel::EMAIL) {
            return self::emailQuery($organizationId, $contactGroupId);
        }

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

    /**
     * جمهور البريد: الشخص عنوانه لا صفّه. صفوف قنوات شتّى قد تحمل العنوان
     * نفسه (واتساب وتيليجرام)، فسجلٌّ واحد لكل عنوان — على أقدم صفّ حيّ،
     * ومنه تُملأ المتغيّرات.
     *
     * يُستبعد:
     *  - عنوانٌ ألغى اشتراكه في بريد المنشأة (email_unsubscribes)؛
     *  - عنوانٌ انسحب أيّ صفّ له من التسويق — الاتّجاه المحافظ؛
     *  - صفوف المحادثة المباشرة: العنوان فيها كتبه زائرٌ مجهول بلا تحقّق،
     *    وإرسال تسويقٍ إليه يُعرّض دومين التاجر للحظر.
     */
    private static function emailQuery(int $organizationId, $contactGroupId): Builder
    {
        $all = empty($contactGroupId) || (string) $contactGroupId === '0';

        return Contact::query()->whereIn('contacts.id', function ($query) use ($organizationId, $contactGroupId, $all) {
            $query->from('contacts as c')
                ->selectRaw('MIN(c.id)')
                ->where('c.organization_id', $organizationId)
                ->whereNull('c.deleted_at')
                ->where('c.channel', '!=', Channel::WEBCHAT)
                ->whereNotNull('c.email')
                ->where('c.email', 'like', '%_@_%')
                ->when(!$all, fn ($q) => $q->whereExists(fn ($member) => $member->selectRaw('1')
                    ->from('contact_contact_group as g')
                    ->whereColumn('g.contact_id', 'c.id')
                    ->where('g.contact_group_id', $contactGroupId)))
                ->whereNotExists(fn ($unsubscribed) => $unsubscribed->selectRaw('1')
                    ->from('email_unsubscribes as u')
                    ->where('u.organization_id', $organizationId)
                    ->whereRaw('u.email = LOWER(TRIM(c.email))'))
                ->whereNotExists(fn ($optedOut) => $optedOut->selectRaw('1')
                    ->from('contacts as o')
                    ->where('o.organization_id', $organizationId)
                    ->whereNull('o.deleted_at')
                    ->whereNotNull('o.marketing_opted_out_at')
                    ->whereRaw('LOWER(TRIM(o.email)) = LOWER(TRIM(c.email))'))
                ->groupByRaw('LOWER(TRIM(c.email))');
        });
    }

    /**
     * لماذا لا تصل الحملة إلى أعضاء المجموعة: عدد المنسحبين من التسويق، وعدد
     * من هم على قناة غير قناة الحملة (ولم ينسحبوا، كي لا يُعدّ أحد مرّتين).
     *
     * تُسأل حين يكون الجمهور فارغاً فقط، فتشرح للتاجر مجموعةً تعرض جهات
     * اتصال وترفضها الحملة.
     *
     * @return array{members: int, opted_out: int, other_channel: int}
     */
    public static function exclusions(int $organizationId, int $contactGroupId, string $channel = Channel::DEFAULT): array
    {
        $members = Contact::where('organization_id', $organizationId)
            ->whereHas('contactGroups', function ($query) use ($contactGroupId) {
                $query->where('contact_groups.id', $contactGroupId);
            });

        return [
            'members' => (clone $members)->count(),
            'opted_out' => (clone $members)->whereNotNull('marketing_opted_out_at')->count(),
            'other_channel' => (clone $members)
                ->whereNull('marketing_opted_out_at')
                ->where('contacts.channel', '!=', $channel)
                ->count(),
        ];
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
