<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * أعمدة القناة على campaigns (١٬٩٣٧ صفّاً).
 *
 * جملة واحدة INSTANT للأعمدة الثلاثة: أجزاء من الثانية، بيانات وصفية فقط.
 * لا فهرس FULLTEXT على campaigns فالطريق مفتوح.
 *
 * message_payload هو الرسالة الحرّة للقنوات الأخرى: {"text": "...",
 * "media": {...}}. المنصّات غير واتساب لا تعرف «القوالب المعتمدة مسبقاً»
 * أصلاً، بل رسالة تُرسَل لمن نافذته مفتوحة.
 *
 * وإرخاء القيد على template_id فُصل في 2026_09_23_100400: تغيير NULLability
 * إعادةُ بناءٍ للجدول لا عملية INSTANT، وفصله يجعل كل ترحيلة مسجَّلة حقيقةً
 * واحدة لها تراجعها الخاصّ — فلو فشلت الثانية بقيت الأولى مسجَّلة نظيفة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasColumn('campaigns', 'channel')) {
            return;
        }

        DB::statement('SET SESSION lock_wait_timeout = 10');

        DB::statement("
            ALTER TABLE campaigns
            ADD COLUMN channel            VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
            ADD COLUMN channel_account_id BIGINT UNSIGNED NULL,
            ADD COLUMN message_payload    TEXT NULL,
            ALGORITHM=INSTANT
        ");
    }

    public function down(): void
    {
        if (!Schema::hasColumn('campaigns', 'channel')) {
            return;
        }

        DB::statement('SET SESSION lock_wait_timeout = 10');

        DB::statement('
            ALTER TABLE campaigns
            DROP COLUMN message_payload,
            DROP COLUMN channel_account_id,
            DROP COLUMN channel,
            ALGORITHM=INSTANT
        ');
    }
};
