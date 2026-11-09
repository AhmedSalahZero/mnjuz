<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * إصلاح لمرّة واحدة: إعادة مزامنة قوالب واتساب من Meta.
 *
 * لا يُغيّر هذا الملفّ المخطّط — هو إصلاح بيانات. وضعناه ترحيلاً لأنه يجب
 * أن يعمل **مرّة واحدة تلقائياً بعد النشر**، بلا أن يتذكّر أحد تشغيله يدوياً.
 *
 * ما يُصلحه
 * ─────────
 * كان مسار تعديل القالب يحفظ الحالة ولا يحفظ metadata (السطر معلَّق منذ أول
 * commit في السكربت الأصلي). فبقيت نسختنا على محتوى قديم، وظهرت الشكوى
 * باسم «الصورة القديمة».
 *
 * ومعه أمرٌ يسهل الخلط فيه:
 *
 *   • **صورة القالب** عند Meta **لا تنتهي**. باقية ما بقي القالب.
 *   • **الرابط** الذي نخزّنه إذنُ وصولٍ موقَّع ينتهي بعد نحو ثلاثين يوماً
 *     (`oe=` في آخره)، فيردّ CDN واتساب 403 والصورة سليمة في مكانها.
 *
 * قياس ٢٣ سبتمبر ٢٠٢٦ على الإنتاج: ١٣٥ من ٢٤٤ قالباً روابطها ميّتة.
 * فهذا الترحيل **يُجدّد الإذن لا الصورة**.
 *
 * والدورة الليلية (templates:sync) تتكفّل بما بعده — هذا للماضي وحده.
 *
 * حدوده
 * ─────
 * ٩٨ منشأة مربوطة بواتساب ⇒ نحو ٢.٥–٥ دقائق من نداءات Meta. والترحيل يعمل
 * داخل deploy.sh والعمّال متوقّفون، فوضعنا حدّاً زمنياً: ما لم يُزامَن
 * تُكمله الدورة الليلية بعد ساعات، والتوقّف تأجيل لا فقدان.
 */
return new class extends Migration
{
    /** حدّ زمني يمنع نشراً معلّقاً لو تعثّرت Meta. */
    private const MAX_SECONDS = 150;

    public function up(): void
    {
        // الاختبارات تُشغّل الترحيلات كلّها مع كل RefreshDatabase. وبلا هذا
        // الحارس ينطلق نداء شبكة حقيقي إلى Meta في كل ملفّ اختبار — يُبطئ
        // السويت ويُفشله حين لا تكون الشبكة متاحة.
        if (app()->environment('testing')) {
            return;
        }

        if (!Schema::hasTable('templates') || !Schema::hasTable('organizations')) {
            return;
        }

        // الترحيل لا يجوز أن يُفشل النشر بحال: هو إصلاح بيانات لا تغيير
        // مخطّط، وفشله يعني قوالب تنتظر الدورة الليلية — لا أكثر.
        try {
            $exitCode = Artisan::call('templates:sync', [
                '--max-seconds' => self::MAX_SECONDS,
                '-v' => true,
            ]);

            $output = trim(Artisan::output());

            Log::info('Template metadata resync migration finished', [
                'exit_code' => $exitCode,
                'output' => mb_substr($output, 0, 4000),
            ]);

            // يظهر في مخرجات artisan migrate أثناء النشر.
            if ($output !== '') {
                echo $output . PHP_EOL;
            }
        } catch (\Throwable $e) {
            Log::error('Template metadata resync migration failed', [
                'error' => $e->getMessage(),
            ]);

            echo 'تعذّرت مزامنة القوالب في الترحيل: ' . $e->getMessage()
                . ' — ستُكملها الدورة الليلية (templates:sync).' . PHP_EOL;
        }
    }

    /**
     * لا تراجع: المزامنة جلبت من Meta ما هو صحيح، وإعادة البيانات القديمة
     * تعني إرجاع روابط ميّتة. والتراجع الحقيقي هو تشغيل المزامنة من جديد.
     */
    public function down(): void
    {
        // متروك عمداً.
    }
};
