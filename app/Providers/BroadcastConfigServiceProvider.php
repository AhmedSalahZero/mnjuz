<?php

namespace App\Providers;

use App\Models\Setting;
use App\Services\Broadcasting\BroadcastProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class BroadcastConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // إعداد البثّ يُطبَّق دائماً، خارج بوّابة ENABLE_DATABASE_CONFIG.
        //
        // كان داخلها، والبوّابة مغلقة — فلم تُطبَّق قطّ. المواضع الثلاثة التي
        // تستدعي apply() (خدمة واتساب، الويب هوك، PayPal) لا يعمل أيٌّ منها
        // داخل وظيفة البثّ المُطابَرة، فكان العامل يبثّ إلى ما في .env مهما قال
        // جدول الإعدادات: بُدّل المزوّد إلى Reverb، وظلّ الوارد يُبثّ إلى سحابة
        // Pusher بينما المتصفّح يستمع إلى Reverb — تُحفظ الرسالة ولا تصل.
        //
        // التطبيق هنا آمن: BroadcastProvider يرتدّ إلى .env عند غياب الصفوف
        // أو تعذّر القاعدة، فالبيئة غير المضبوطة تبقى كما كانت.
        BroadcastProvider::apply();

        // عامل الطابور عملية طويلة العمر تقرأ الإعدادات مرّة وتحتفظ بها. بلا
        // هذا السطر يبقى على المزوّد القديم حتى يُعاد تشغيله يدوياً — تبديل
        // نصفيّ: الداشبورد ينتقل والرسائل الخلفية لا. استعلام صغير قبل كل
        // وظيفة ثمنٌ زهيد مقابل أن يكون التبديل لحظياً وكاملاً.
        Queue::looping(function () {
            BroadcastProvider::forget();
            BroadcastProvider::apply();
        });

        if (!env('ENABLE_DATABASE_CONFIG', false)) {
            return;
        }

        // السائق الافتراضي (pusher / log / null) يبقى خلف البوّابة: تعطيل البثّ
        // كلّياً من جدول الإعدادات قرار مختلف عن اختيار وجهته.
        $driver = trim((string) (Setting::where('key', 'broadcast_driver')->value('value') ?? ''));
        if ($driver !== '') {
            Config::set('broadcasting.default', $driver);
        }
    }
}
