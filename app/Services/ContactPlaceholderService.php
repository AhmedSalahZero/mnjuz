<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class ContactPlaceholderService
{
    /**
     * اسم الحقل ⇐ الرمز الذي يُكتب داخل الأقواس.
     *
     * يجب أن يوافق تماماً ما تبحث عنه replace() في مفاتيح metadata، وإلا
     * ظهر المتغيّر في القائمة ولم يُستبدَل عند الإرسال. لذلك mb_strtolower
     * لا strtolower — الثانية لا تُصغّر الحروف غير اللاتينية — و trim لأن
     * اسماً بمسافة طرفية كان يُنتج رمزاً بشرطة سفلية زائدة.
     */
    public static function tokenFor(string $name): string
    {
        return mb_strtolower(str_replace(' ', '_', trim($name)));
    }

    /**
     * متغيّرات الرسائل التي تُعرض للمستخدم: الثابتة ثمّ حقول المنشأة المخصّصة.
     *
     * كانت تُبنى في ثلاثة مواضع بكود مكرّر — إعدادات أوقات العمل، وإنشاء
     * الردّ الجاهز، وتعديله — فاختلفت: صفحة التعديل كانت تُسقط نسخ
     * {url:...} للحقول المخصّصة، فيرى المستخدم متغيّرات في الإنشاء تختفي
     * عند التعديل. المصدر هنا واحد لكل من يعرضها، والتطبيق معهم.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function optionsForOrganization(int $organizationId): array
    {
        $builtIn = config('formats.placeholders');

        if (!is_array($builtIn)) {
            $builtIn = [];
        }

        $names = DB::table('contact_fields')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('name');

        $custom = [];
        $urlEncoded = [];

        foreach ($names as $name) {
            $token = self::tokenFor((string) $name);

            $custom[] = ['value' => '{' . $token . '}', 'label' => (string) $name];
            $urlEncoded[] = ['value' => '{url:' . $token . '}', 'label' => $name . ' (URL encoded)'];
        }

        return array_merge($builtIn, $custom, $urlEncoded);
    }

    /**
     * Replace `{field}` and `{url:field}` tokens using contact and organization data (same rules as canned replies).
     */
    public static function replace(int $organizationId, string $contactUuid, string $message): string
    {
        $organization = Organization::where('id', $organizationId)->first();
        $contact = Contact::with('contactGroups')->where('uuid', $contactUuid)->first();
        if (!$organization || !$contact) {
            return $message;
        }

        $address = $contact->address ? json_decode($contact->address, true) : [];
        $metadata = $contact->metadata ? json_decode($contact->metadata, true) : [];

        // الأجزاء الموجودة وحدها. الوصل غير المشروط كان يُنتج «, , , , »
        // لعميلٍ بلا عنوان — خمس فواصل تصل العميل في رسالته بدل فراغ.
        $full_address = collect([
            $address['street'] ?? null,
            $address['city'] ?? null,
            $address['state'] ?? null,
            $address['zip'] ?? null,
            $address['country'] ?? null,
        ])->map(fn ($part) => trim((string) $part))
          ->filter(fn ($part) => $part !== '')
          ->implode(', ');

        $data = [
            'first_name' => $contact->first_name ?? null,
            'last_name' => $contact->last_name ?? null,
            'full_name' => $contact->full_name ?? null,
            'email' => $contact->email ?? null,
            'phone' => $contact->phone ?? null,
            'organization_name' => $organization->name,
            'full_address' => $full_address,
            'street' => $address['street'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'zip_code' => $address['zip'] ?? null,
            'country' => $address['country'] ?? null,
            // {group} معروض في قائمة المتغيّرات منذ البداية ولم يكن يُستبدَل
            // أبداً: العلاقة تُجلب أعلاه ولا تُستعمل، فيصل العميل «{group}»
            // حرفياً في رسالته. والعميل قد يكون في أكثر من مجموعة، فنصلها
            // بفاصلة كما يعرضها جدول جهات الاتصال.
            'group' => $contact->contactGroups
                ->pluck('name')
                ->filter(fn ($name) => trim((string) $name) !== '')
                ->implode(', '),
        ];

        $transformedMetadata = [];
        if ($metadata) {
            foreach ($metadata as $key => $value) {
                $transformedKey = mb_strtolower(str_replace(' ', '_', trim((string) $key)));
                $transformedMetadata[$transformedKey] = $value;
            }
        }

        $mergedData = array_merge($data, $transformedMetadata);

        // المُعدِّل u ضروري: بدونه \w حروفٌ لاتينية فقط، فحقلٌ مخصّص باسم عربي
        // مثل «عدد الطلبات» لا يُطابَق أبداً — يكتبه المستخدم {عدد_الطلبات}
        // فيصل العميل الرمز كما هو، بلا خطأ يشير إلى السبب.
        // array_key_exists لا isset: الثانية false للقيمة null، فحقلٌ لم يملأه
        // العميل كان يُبقي رمزه ظاهراً — تصل الرسالة وفيها «{email}» حرفياً.
        // والمعرفة بوجود المفتاح هي المقصودة: الرمز المجهول وحده يبقى كما هو.
        $message = preg_replace_callback('/\{url:([\w\x{0600}-\x{06FF}]+)\}/u', function ($matches) use ($mergedData) {
            $key = $matches[1];
            if (array_key_exists($key, $mergedData)) {
                return rawurlencode(self::stringify($mergedData[$key]));
            }

            return $matches[0];
        }, $message);

        return preg_replace_callback('/\{([\w\x{0600}-\x{06FF}]+)\}/u', function ($matches) use ($mergedData) {
            $key = $matches[1];
            if (array_key_exists($key, $mergedData)) {
                return self::stringify($mergedData[$key]);
            }

            return $matches[0];
        }, $message);
    }

    /**
     * قيمة الحقل نصّاً.
     *
     * الحقول المخصّصة تُحفظ في JSON فتصل أرقاماً ومنطقيّات ومصفوفات. و(string)
     * وحدها ترمي على المصفوفة، وتُنتج "1" و"" للمنطقيّات — وهو ما لا يفهمه
     * قارئ الرسالة.
     */
    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return implode(', ', array_filter(array_map(
                static fn ($item) => is_scalar($item) ? trim((string) $item) : '',
                $value
            ), static fn ($item) => $item !== ''));
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
