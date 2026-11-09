<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * من هو متصل الآن: صفّ لكل «عميل» حيّ — تبويب في المتصفّح أو جلسة تطبيق.
     *
     * الحضور كان آخر نبضة للموظف كلّه، فإغلاق تبويب من اثنين أو الخروج من
     * الويب والتطبيق مفتوح لا يمكن تمييزه عن مغادرته. الموظف متصل ما دام له
     * عميل واحد حيّ. الجدول لا يحفظ إلا الأحياء: المنظّف يحذف ما انقطعت نبضته.
     */
    public function up(): void
    {
        if (Schema::hasTable('agent_presence_clients')) {
            return;
        }

        Schema::create('agent_presence_clients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->string('client_id', 64);
            $table->string('platform', 16);
            // جلسة الويب أو توكن التطبيق: الخروج يُسقط عملاء جلسته وحدها.
            $table->string('session_key', 128)->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['organization_id', 'user_id', 'client_id'], 'uniq_agent_presence_client');
            $table->index(['organization_id', 'last_seen_at'], 'idx_agent_presence_org_seen');
            $table->index('last_seen_at', 'idx_agent_presence_seen');
            $table->index(['user_id', 'session_key'], 'idx_agent_presence_session');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_presence_clients');
    }
};
