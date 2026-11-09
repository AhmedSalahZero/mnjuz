<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * زوّار المحادثة المباشرة (widget في موقع التاجر).
 *
 * لماذا جدول مستقلّ لا أعمدة على contacts؟
 *
 *   - الزائر يوجد قبل جهة الاتصال: يملأ النموذج فيأخذ توكنه، وجهة الاتصال
 *     تُنشأ مع أول رسالة فقط — فلا تمتلئ صندوق المحادثات بمحادثات فارغة.
 *   - رقمه لا يدخل contacts.phone: ذاك مفتاح هوية واتساب الفريد
 *     (organization_id, phone_active_key)، ورقم الزائر قد يطابق جهة واتساب
 *     قائمة فيتصادمان. هنا يُحفظ للعرض والسياق وحده.
 *   - التوكن وبيانات الجلسة (الصفحة، المتصفّح، الـ IP) لا مكان لها في
 *     contacts ولا يجوز أن تصل إلى webhook العميل.
 *
 * جدول جديد: لا يمسّ أي جدول قائم.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('webchat_visitors')) {
            return;
        }

        Schema::create('webchat_visitors', function (Blueprint $table) {
            $table->id();
            // المعرّف العامّ للزائر — هو contacts.channel_identifier لجهته.
            $table->char('uuid', 36);
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('channel_account_id');
            // يُملأ مع أول رسالة. فريد: جهة اتصال واحدة لكل زائر.
            $table->unsignedBigInteger('contact_id')->nullable();

            // sha256 للتوكن الذي يحمله المتصفّح. التوكن نفسه لا يُخزَّن.
            $table->char('token_hash', 64);

            $table->string('name', 120)->nullable();
            // E.164 كما تحقّقنا منه عند النموذج.
            $table->string('phone', 32)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('locale', 10)->nullable();

            $table->string('first_page_url', 2048)->nullable();
            $table->string('last_page_url', 2048)->nullable();
            $table->string('referrer', 2048)->nullable();
            $table->string('user_agent', 512)->nullable();
            // مقصوص: آخر مقطع في IPv4 والنصف الأخير في IPv6 — يكفي للحدّ من
            // الإساءة ولا يُخزّن هوية دقيقة.
            $table->string('ip', 64)->nullable();
            $table->string('country', 2)->nullable();

            // علامة القراءة: آخر chats.id رآه الزائر — منها عدد غير المقروء.
            $table->unsignedBigInteger('last_read_chat_id')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('uuid', 'webchat_visitors_uuid_unique');
            $table->unique('token_hash', 'webchat_visitors_token_hash_unique');
            $table->unique('contact_id', 'webchat_visitors_contact_id_unique');
            $table->index(['channel_account_id', 'created_at'], 'webchat_visitors_account_created_index');
            $table->index('organization_id', 'webchat_visitors_organization_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webchat_visitors');
    }
};
