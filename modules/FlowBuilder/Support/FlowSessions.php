<?php

namespace Modules\FlowBuilder\Support;

use Illuminate\Support\Facades\Schema;
use Modules\FlowBuilder\Models\FlowUserData;

/**
 * إنهاء جلسة العميل في الـ flow حين يتولّى إنسانٌ المحادثة.
 *
 * كان الـ flow يُكمل إرسال خطواته فوق كلام الموظف: يجيب العميلُ الموظفَ،
 * فتُقرأ إجابته ردّاً على عقدة الـ flow ويُرسَل ما بعدها.
 */
final class FlowSessions
{
    private static ?bool $tableExists = null;

    public static function endForContact(?int $contactId): void
    {
        if (!$contactId || !self::tableExists()) {
            return;
        }

        FlowUserData::where('contact_id', $contactId)->delete();
    }

    /**
     * الجدول من ترحيلات الوحدة: قد يغيب في تثبيت بلا الوحدة أو في الاختبارات.
     *
     * نحفظ الجواب الإيجابي وحده. حفظ «غير موجود» كان يبقى طوال عمر العامل:
     * عاملٌ بدأ قبل ترحيل الوحدة لا يُنهي أي جلسة حتى يُعاد تشغيله، وفي
     * الاختبارات يُعطّل الإنهاء لكل ما بعد أول اختبار سبق إنشاء الجدول.
     */
    private static function tableExists(): bool
    {
        if (self::$tableExists !== true) {
            self::$tableExists = Schema::hasTable('flow_user_data');
        }

        return self::$tableExists;
    }
}
