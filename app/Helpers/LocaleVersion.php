<?php

namespace App\Helpers;

use Illuminate\Support\Facades\File;

/**
 * بصمة ملفات الترجمة.
 *
 * الواجهة تحتفظ بالترجمات في localStorage أربعاً وعشرين ساعة بلا فحص، فكل
 * مفتاح يُضاف يظهر خاماً عند العميل إلى أن تنتهي المهلة — رأينا ذلك على
 * «{count} files» تحت ألبوم الصور. البصمة تُرسَل في وسم <meta> مع كل تحميل
 * للصفحة، فيقارنها المتصفّح بما خزّنه ويُسقطه إن تغيّر: بلا طلب إضافي وبلا
 * انتظار.
 */
final class LocaleVersion
{
    /**
     * أحدث تعديل على ملف اللغة الحالية.
     *
     * القراءة من نظام الملفات في كل طلب رخيصة (stat واحد)، لكنّها في مسار كل
     * صفحة — فنُبقيها في ذاكرة الطلب.
     */
    public static function current(?string $locale = null): string
    {
        static $cache = [];

        $locale = $locale ?: app()->getLocale();

        if (!isset($cache[$locale])) {
            $path = base_path("lang/{$locale}.json");
            $cache[$locale] = File::exists($path) ? (string) File::lastModified($path) : '0';
        }

        return $cache[$locale];
    }
}
