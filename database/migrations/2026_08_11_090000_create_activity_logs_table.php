<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ نشاط أعضاء المنظمة. يُحتفظ به سبعة أيام ثم يُحذف بـ activity:prune،
 * فلا مبرّر لمفاتيح أجنبية تُبطئ الكتابة على مسار ساخن ولا لـ updated_at:
 * الصفّ يُكتب مرّة ولا يُعدَّل أبداً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 191)->nullable();   // لقطة الاسم وقت الفعل: العضو قد يُحذف
            $table->string('event', 64);                     // من كتالوج ActivityLogger
            $table->string('subject_type', 64)->nullable();  // contact، chat، ticket…
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 191)->nullable(); // اسم العميل مثلاً، ليبقى مفهوماً بعد حذفه
            $table->json('properties')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            // الصفحة تعرض نشاط منظمة مرتّباً بالأحدث، والحذف يمسح ما قدُم.
            $table->index(['organization_id', 'created_at'], 'activity_logs_org_created_idx');
            $table->index(['organization_id', 'user_id', 'created_at'], 'activity_logs_org_user_created_idx');
            $table->index('created_at', 'activity_logs_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
