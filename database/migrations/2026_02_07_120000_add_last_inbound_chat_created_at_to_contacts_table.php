<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * عمود بديل عن استعلام lastInboundChat — يُحدَّث تلقائياً عند إنشاء رسالة inbound.
     * القيمة الافتراضية: null = "لم يرسل العميل أي رسالة بعد".
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('last_inbound_chat_created_at')->nullable()->after('latest_chat_created_at');
            $table->index('last_inbound_chat_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['last_inbound_chat_created_at']);
            $table->dropColumn('last_inbound_chat_created_at');
        });
    }
};
