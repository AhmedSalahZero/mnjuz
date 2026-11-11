<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * القنوات التي يعمل عليها كل ردّ آلي وكل flow.
 *
 * كانت الأتمتة واحدة لكل القنوات: ترحيب واتساب يجيب زائر الموقع أيضاً. الآن
 * يختار التاجر لكلٍّ منها قنواته (واتساب، تيليجرام، المحادثة المباشرة).
 *
 * القائم يُعبّأ بما كان يعمل عليه فعلاً — واتساب والمحادثة المباشرة — فلا
 * يتغيّر ردّ اليوم، ولا يبدأ بوتٌ كُتب لواتساب يجيب على تيليجرام حين تُشغَّل
 * أتمتته. وnull يبقى معناه «كل القنوات» لصفٍّ لم يمرّ بالتعبئة.
 *
 * ترحيلات وحدة FlowBuilder لا تعمل مع `php artisan migrate`، فعمود flows مضاف
 * في ترحيل إنشاء الجدول (للتثبيت الجديد) وهنا (للقواعد القائمة).
 */
return new class extends Migration
{
    /** AutomationChannels::LEGACY منسوخةً: الترحيل لا يتبع تغيّر الكود بعده. */
    private const LEGACY = '["whatsapp","webchat"]';

    public function up(): void
    {
        if (Schema::hasTable('auto_replies') && !Schema::hasColumn('auto_replies', 'channels')) {
            Schema::table('auto_replies', function (Blueprint $table) {
                $table->json('channels')->nullable()->after('match_criteria');
            });

            DB::table('auto_replies')->whereNull('channels')->update(['channels' => self::LEGACY]);
        }

        if (Schema::hasTable('flows') && !Schema::hasColumn('flows', 'channels')) {
            Schema::table('flows', function (Blueprint $table) {
                $table->json('channels')->nullable()->after('trigger_timeout');
            });

            DB::table('flows')->whereNull('channels')->update(['channels' => self::LEGACY]);
        }
    }

    public function down(): void
    {
        foreach (['auto_replies', 'flows'] as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'channels')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropColumn('channels');
                });
            }
        }
    }
};
