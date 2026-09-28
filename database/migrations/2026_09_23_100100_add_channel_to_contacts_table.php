<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * أعمدة القناة على contacts (٨١٧٬٣٥٦ صفّاً على الإنتاج).
 *
 * كل شيء هنا إضافي: لا عمود قائم يُعدَّل، ولا فهرس قائم يُمَسّ، ولا صفّ
 * يُكتب. القيمة الافتراضية 'whatsapp' تجعل كل صفّ قائم صحيحاً لحظة الترحيل
 * بلا تعبئة رجعية (backfill).
 *
 * الخوارزميات — مقيسة على نسخة كاملة من الإنتاج لا مقروءة من التوثيق:
 *
 *   الأعمدة الثلاثة معاً   INPLACE, LOCK=NONE   ٢٢ ثانية   (إعادة بناء على الخطّ)
 *   العمود المُولَّد        INSTANT              أجزاء من الثانية
 *   الفهرس الفريد         INPLACE, LOCK=NONE   ٤٫٧ ثانية
 *   فهرس البحث            INPLACE, LOCK=NONE   ٥٫٥ ثانية
 *   فهرس الفلترة          INPLACE, LOCK=NONE   ٥٫٠ ثوانٍ
 *
 * القراءة والكتابة تستمرّان في كل واحدة منها.
 *
 * ولماذا الأعمدة الثلاثة في جملة واحدة؟ لأن كلّ ADD COLUMN هنا إعادةُ بناءٍ
 * كاملة لجدول حجمه ٦٣٥ ميغابايت. ثلاث جمل = ثلاث إعادات بناء، وجملة واحدة
 * = واحدة.
 *
 * شرط لازم: 2026_09_23_100050 حذف فهرس FULLTEXT قبل هذا الملفّ. مع وجوده
 * تهبط كل جملة أدناه إلى COPY وتحجب الكتابة دقائق. لذلك نتحقّق أولاً
 * ونتوقّف بخطأ واضح بدل أن نعلّق الإنتاج.
 *
 * phone يبقى كما هو ويبقى فهرسه الفريد كما هو. جهات الاتصال الجديدة تحمل
 * phone = NULL، وMySQL يقبل تكرار NULL في الفهارس الفريدة — أي أننا لا نلمس
 * contacts_org_phone_active_unique إطلاقاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if ($this->indexExists('contacts', 'fulltext_contacts_name_email_phone')) {
            throw new RuntimeException(
                'فهرس FULLTEXT ما زال على contacts. شغّل '
                . '2026_09_23_100050_drop_unused_fulltext_index_from_contacts_table أولاً — '
                . 'وإلّا هبطت كل ALTER هنا إلى COPY وحجبت الكتابة نحو ١١ دقيقة.'
            );
        }

        DB::statement('SET SESSION lock_wait_timeout = 10');

        // ─── الأعمدة الثلاثة: إعادة بناء واحدة على الخطّ ───────────────────
        if (!Schema::hasColumn('contacts', 'channel')) {
            DB::statement("
                ALTER TABLE contacts
                ADD COLUMN channel            VARCHAR(20) NOT NULL DEFAULT 'whatsapp' AFTER phone,
                ADD COLUMN channel_identifier VARCHAR(191) NULL                       AFTER channel,
                ADD COLUMN channel_account_id BIGINT UNSIGNED NULL                    AFTER channel_identifier,
                ALGORITHM=INPLACE, LOCK=NONE
            ");
        }

        // ─── فريد على الحيّ فقط ────────────────────────────────────────────
        // نفس حيلة phone_active_key: MySQL لا يدعم partial indexes، والعمود
        // المُولَّد يُرجع NULL للصفوف المحذوفة، وتكرار NULL مسموح في الفهرس
        // الفريد. فجهة الاتصال المحذوفة لا تمنع عودة العميل نفسه لاحقاً.
        if (!Schema::hasColumn('contacts', 'channel_identifier_active_key')) {
            DB::statement("
                ALTER TABLE contacts
                ADD COLUMN channel_identifier_active_key VARCHAR(191)
                    GENERATED ALWAYS AS (IF(deleted_at IS NULL, channel_identifier, NULL)) VIRTUAL,
                ALGORITHM=INSTANT
            ");
        }

        if (!$this->indexExists('contacts', 'contacts_org_channel_ident_active_unique')) {
            DB::statement("
                ALTER TABLE contacts
                ADD UNIQUE INDEX contacts_org_channel_ident_active_unique
                    (organization_id, channel, channel_identifier_active_key),
                ALGORITHM=INPLACE, LOCK=NONE
            ");
        }

        // ─── فهرس البحث — ليس ترفاً ────────────────────────────────────────
        // الفهرس الفريد أعلاه يَحرُس ولا يَبحث. ContactResolver يسأل:
        //   WHERE organization_id = ? AND channel = ? AND channel_identifier = ?
        // وهو يسأل عن العمود الحقيقي لا عن المُولَّد، وMySQL لا يبدّل بينهما
        // إلّا إذا طابق التعبيرُ التعبيرَ حرفياً. فالفهرس الفريد يخدم أول
        // عمودين فقط. قياس EXPLAIN على ٨١٧ ألف صفّ:
        //   بلا هذا الفهرس:  key_len 86   rows 8
        //   ومعه:            key_len 853  rows 1
        // والاستعلام يجري مع كل رسالة واردة، فالفرق يتضخّم مع كبار المنشآت.
        if (!$this->indexExists('contacts', 'idx_contacts_org_channel_identifier')) {
            DB::statement("
                ALTER TABLE contacts
                ADD INDEX idx_contacts_org_channel_identifier
                    (organization_id, channel, channel_identifier),
                ALGORITHM=INPLACE, LOCK=NONE
            ");
        }

        // ─── فلترة قائمة المحادثات حسب المنصّة ─────────────────────────────
        // نفس ترتيب idx_contacts_org_deleted_latest_chat مع القناة مُقحَمة،
        // ليخدم الاستعلام نفسه حين يُضاف شرط القناة.
        if (!$this->indexExists('contacts', 'idx_contacts_org_channel_latest_chat')) {
            DB::statement("
                ALTER TABLE contacts
                ADD INDEX idx_contacts_org_channel_latest_chat
                    (organization_id, channel, deleted_at, latest_chat_created_at),
                ALGORITHM=INPLACE, LOCK=NONE
            ");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('SET SESSION lock_wait_timeout = 10');

        foreach ([
            'idx_contacts_org_channel_latest_chat',
            'idx_contacts_org_channel_identifier',
            'contacts_org_channel_ident_active_unique',
        ] as $index) {
            if ($this->indexExists('contacts', $index)) {
                DB::statement("ALTER TABLE contacts DROP INDEX {$index}, ALGORITHM=INPLACE, LOCK=NONE");
            }
        }

        if (Schema::hasColumn('contacts', 'channel_identifier_active_key')) {
            DB::statement('ALTER TABLE contacts DROP COLUMN channel_identifier_active_key, ALGORITHM=INSTANT');
        }

        // الأعمدة الثلاثة في جملة واحدة كذلك: إعادة بناء واحدة لا ثلاث.
        if (Schema::hasColumn('contacts', 'channel')) {
            DB::statement('
                ALTER TABLE contacts
                DROP COLUMN channel_account_id,
                DROP COLUMN channel_identifier,
                DROP COLUMN channel,
                ALGORITHM=INPLACE, LOCK=NONE
            ');
        }
    }

    protected function indexExists(string $table, string $index): bool
    {
        return count(DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$index])) > 0;
    }
};
