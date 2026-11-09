<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حسابات المنصّات المربوطة: بوت تيليجرام، صفحة فيسبوك، حساب إنستغرام،
 * حساب تيك توك للأعمال.
 *
 * جدول جديد فارغ — إنشاؤه لا يمسّ أي بيانات قائمة ولا يأخذ أي قفل على
 * جداول الإنتاج.
 *
 * واتساب تبقى في organizations.metadata.whatsapp كما هي. نقلها إلى هنا
 * ترحيلُ بياناتٍ مستقلّ لا يشترطه عمل بقيّة القنوات، وتأجيله يُبقي مسار
 * واتساب المُجرَّب بلا مساس.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('channel_accounts')) {
            return;
        }

        Schema::create('channel_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 50);
            $table->unsignedBigInteger('organization_id');

            // اسم القناة كما في App\Services\Messaging\Channel.
            $table->string('channel', 20);

            // معرّف الحساب لدى المنصّة: bot id / page id / ig account id /
            // business open id. هو مفتاح توجيه الـ webhook.
            $table->string('external_id', 191);

            $table->string('name', 191)->nullable();
            $table->string('username', 191)->nullable();
            $table->string('avatar', 255)->nullable();

            // JSON مشفّر (encrypted:array) — توكنات تعطي سيطرة كاملة على
            // حساب العميل، فلا تُخزَّن نصّاً صريحاً. TEXT لأن التشفير يُضخّم
            // الناتج نحو الثلث ويُرجعه base64.
            $table->text('credentials')->nullable();

            $table->text('settings')->nullable();

            // ٣٢ لا ٢٠: أطول قيمة اليوم 'reauthorization_required' وطولها
            // أربعة وعشرون حرفاً، والعشرون تقطعها فيرمي MySQL خطأ 1406 عند
            // أول توكن منتهٍ — وهو أسوأ وقت لعطب جديد.
            $table->string('status', 32)->default('active');
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->text('last_error')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('uuid', 'channel_accounts_uuid_unique');
            $table->index('organization_id', 'channel_accounts_organization_id_index');

            // تجديد التوكنات بمهمّة مجدولة تسأل: أيّها يوشك على الانتهاء؟
            $table->index(['status', 'token_expires_at'], 'channel_accounts_status_expiry_index');
        });

        // فريد على الحسابات الحيّة فقط — نفس حيلة العمود المُولَّد المستعملة
        // في contacts و chats، لأن MySQL لا يدعم partial indexes: الحساب
        // المحذوف (soft delete) يجب ألّا يمنع ربط نفس الحساب من جديد.
        DB::statement("
            ALTER TABLE channel_accounts
            ADD COLUMN external_id_active_key VARCHAR(191)
                GENERATED ALWAYS AS (IF(deleted_at IS NULL, external_id, NULL)) VIRTUAL
        ");

        DB::statement("
            ALTER TABLE channel_accounts
            ADD UNIQUE INDEX channel_accounts_org_channel_external_unique
                (organization_id, channel, external_id_active_key)
        ");

        /**
         * وفريد عالمياً كذلك على (channel, external_id): الحساب الواحد
         * لمنشأة واحدة لا أكثر.
         *
         * السبب ليس نظرياً. ChannelAccount::findForWebhook يبحث بـ
         * (channel, external_id) ثم ->first(). فلو ربطت منشأتان البوت نفسه
         * لعادت الأولى دائماً، والثانية لا يصلها شيء أبداً — بلا خطأ ولا
         * سجلّ. وفوق ذلك يُنمَّق معرّف الرسالة بمعرّف الحساب، وهو نفسه
         * للمنشأتين، فتصطدم رسالة الثانية بخطأ تكرار ويبتلعها
         * InboundMessageRecorder صامتاً.
         *
         * القيد هنا يُحوّل العطب الصامت إلى خطأ ظاهر لحظة الربط. وهو يخدم
         * توجيه الـ webhook أيضاً بوصفه بادئة يسرى، فلا حاجة إلى فهرس ثانٍ
         * على العمودين نفسيهما.
         */
        DB::statement("
            ALTER TABLE channel_accounts
            ADD UNIQUE INDEX channel_accounts_channel_external_active_unique
                (channel, external_id_active_key)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_accounts');
    }
};
