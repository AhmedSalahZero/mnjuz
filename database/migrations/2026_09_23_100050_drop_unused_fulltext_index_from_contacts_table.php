<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حذف فهرس FULLTEXT من contacts.
 *
 * لماذا قبل أعمدة القنوات؟ لأن وجود فهرس FULLTEXT على الجدول يمنع
 * MySQL 8.4 من تنفيذ أي ADD COLUMN بـ INSTANT أو بـ INPLACE. قِسناه:
 *
 *   والفهرس موجود:
 *     ALTER ... ADD COLUMN ..., ALGORITHM=INSTANT
 *       → ERROR 1846: ALGORITHM=INSTANT is not supported. Reason: InnoDB
 *         presently supports one FULLTEXT index creation at a time.
 *     ALTER ... ADD COLUMN ..., ALGORITHM=INPLACE, LOCK=NONE
 *       → ERROR 1846 كذلك، «Try ALGORITHM=COPY».
 *     ALTER ... ADD COLUMN ...  (بلا بند)
 *       → COPY. ثلاث دقائق و٤٨ ثانية لعمود واحد على نسخة كاملة من الإنتاج
 *         (٨١٧٬٣٥٦ صفّاً)، والكتابة محجوبة طوال المدّة. والملفّ التالي
 *         يُضيف ثلاثة أعمدة — أي نحو إحدى عشرة دقيقة ونصف.
 *
 *   وبعد حذفه:
 *     ALTER ... ADD COLUMN ×٣, ALGORITHM=INPLACE, LOCK=NONE
 *       → ٢٢ ثانية، والقراءة والكتابة تستمرّان (أُثبت بتشغيل INSERT وUPDATE
 *         أثناء التنفيذ: نجحا في أجزاء من الألف من الثانية).
 *
 * والحجب هنا ليس خطأً يعود للعميل بل تعليقاً: lock_wait_timeout على
 * الإنتاج سنة كاملة. وكل رسالة واردة تكتب في contacts — خطّاف Chat::created
 * يحدّث latest_chat_created_at — فتتراكم العمّال وعمليات php-fpm على
 * max_connections = 151 حتى يسقط الموقع كلّه.
 *
 * والفهرس نفسه لا يستعمله أحد: لا MATCH ... AGAINST ولا whereFullText ولا
 * أي ذكر لاسمه في app/ ولا modules/ ولا resources/ ولا routes/ ولا config/.
 * والبحث في جهات الاتصال يمرّ على Contact::scopeSearchTerm وهي تستعمل LIKE
 * والفهارس العادية (idx_contacts_first_name وأخواتها) — قائمة وتعمل.
 *
 * الحذف نفسه INPLACE مع LOCK=NONE: أجزاء من الثانية، لا يُعيد بناء الجدول
 * ولا يحجب قراءةً ولا كتابة.
 *
 * ملاحظة على الترتيب: حذف الفهرس وحده لا يكفي لإتاحة INSTANT — يبقى عمود
 * FTS_DOC_ID المخفيّ وفهرسه، وMySQL يردّ حينها ERROR 1845 (تحقّقنا). الذي
 * يُزيلهما هو أول إعادة بناء بعده، وهي إضافة الأعمدة في الملفّ التالي. ومن
 * بعدها يصير كل عمود جديد على contacts INSTANT حقاً.
 */
return new class extends Migration
{
    private const INDEX = 'fulltext_contacts_name_email_phone';

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (!$this->indexExists('contacts', self::INDEX)) {
            return;
        }

        // انتظار قفل البيانات الوصفية محدود بعشر ثوانٍ: لو أمسك استعلامٌ
        // طويل الجدولَ، نريد أن تفشل الترحيلة سريعاً لا أن تصطفّ خلفها كل
        // طلبات التطبيق. الملفّ محروس بالكامل فإعادة التشغيل آمنة.
        DB::statement('SET SESSION lock_wait_timeout = 10');

        DB::statement('ALTER TABLE contacts DROP INDEX ' . self::INDEX . ', ALGORITHM=INPLACE, LOCK=NONE');
    }

    /**
     * الرجوع يُعيد الفهرس كما كان.
     *
     * تحذير: إنشاء فهرس FULLTEXT يستلزم LOCK=SHARED — MySQL يرفض LOCK=NONE
     * صراحةً. أي أن الكتابة على contacts محجوبة نحو ٣٦ ثانية أثناء الرجوع.
     * القراءة تستمرّ. نفّذه في وقت هادئ.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if ($this->indexExists('contacts', self::INDEX)) {
            return;
        }

        DB::statement('
            ALTER TABLE contacts
            ADD FULLTEXT KEY ' . self::INDEX . ' (first_name, last_name, phone, email),
            ALGORITHM=INPLACE, LOCK=SHARED
        ');
    }

    protected function indexExists(string $table, string $index): bool
    {
        return count(DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$index])) > 0;
    }
};
