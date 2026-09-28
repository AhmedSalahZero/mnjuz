<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * عمود القناة على chats (٣٬٢٧٨٬٠٥٦ صفّاً، ٦٫٢ غيغابايت على الإنتاج).
 *
 * INSTANT حقيقيّة هنا — لا فهرس FULLTEXT على chats بخلاف contacts. التغيير
 * في القاموس وحده ولا يلمس صفّاً واحداً، فزمنه أجزاء من الثانية مهما بلغ
 * عدد الصفوف.
 *
 * ALGORITHM=INSTANT مذكورة صراحةً: لو رفضها المحرّك يوماً نريد خطأً فورياً،
 * لا هبوطاً صامتاً إلى COPY يُعيد بناء ٦٫٢ غيغابايت ويحجب الكتابة.
 *
 * لا فهرس جديد هنا عمداً — الاستعلامات كلّها تبدأ من contact_id أو
 * organization_id، والقناة تُعرف من جهة الاتصال. إضافة فهرس على ٣٫٢ مليون
 * صفّ تكلفة بلا طالب.
 *
 * والفهرس الفريد القائم على wam_id_active_key فريد عالمياً، ويبقى كما هو:
 * معرّفات القنوات الجديدة تُنمَّق ببادئة القناة ومعرّف الحساب
 * (App\Services\Messaging\Support\MessageId) فلا تتصادم مع واتساب ولا مع
 * بعضها. وكل الصفوف القائمة لها wam_id غير NULL، فلا شيء يتغيّر عليها.
 *
 * يبقى خطرٌ واحد لا يُعالَج هنا بل في channel_accounts: منشأتان تربطان البوت
 * نفسه تتقاسمان معرّف الحساب، فيتطابق المعرّف المنمَّق وتُبتلع رسالة الثانية.
 * القيد الفريد على (channel, external_id) هناك يمنع الحالة من أصلها.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasColumn('chats', 'channel')) {
            return;
        }

        DB::statement('SET SESSION lock_wait_timeout = 10');

        DB::statement("
            ALTER TABLE chats
            ADD COLUMN channel VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
            ALGORITHM=INSTANT
        ");
    }

    public function down(): void
    {
        if (Schema::hasColumn('chats', 'channel')) {
            DB::statement('SET SESSION lock_wait_timeout = 10');
            DB::statement('ALTER TABLE chats DROP COLUMN channel, ALGORITHM=INSTANT');
        }
    }
};
