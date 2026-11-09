<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * استيراد سجلّ المحادثات من منصّة أخرى.
     *
     * الحالة والتقدّم في جدول لا في الذاكرة المؤقّتة: استيراد مئتي ألف رسالة
     * يستغرق دقائق، ومن يُعيد تحميل الصفحة في منتصفه يجب أن يجده حيث تركه.
     * وcursor_offset موضع الشريحة التالية في الملف — به تُستأنف الكتابة بعد
     * أي انقطاع دون أن تبدأ من أوّلها.
     */
    public function up(): void
    {
        if (!Schema::hasTable('chat_imports')) {
            Schema::create('chat_imports', function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 50)->unique();
                // معرّف الرفع المجزّأ: إعادة إرسال القطعة الأخيرة لا تُنشئ استيراداً ثانياً.
                $table->string('upload_id', 64)->unique();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('user_id');
                $table->string('original_name', 255);
                $table->string('file_path', 500)->nullable();
                $table->unsignedBigInteger('file_size')->default(0);
                $table->string('default_country', 2)->nullable();
                $table->string('timezone', 64)->default('UTC');
                // لغة من رفع الملف: رسائل الأخطاء تُكتب في الطابور، ولا جلسة هناك تحمل لغته.
                $table->string('locale', 8)->default('en');
                $table->string('status', 32)->default('pending_validation');

                $table->unsignedBigInteger('progress_bytes')->default(0);
                $table->unsignedBigInteger('cursor_offset')->default(0);
                $table->unsignedInteger('total_rows')->default(0);
                $table->unsignedInteger('processed_rows')->default(0);
                $table->unsignedInteger('new_messages_estimate')->default(0);
                $table->unsignedInteger('new_contacts_estimate')->default(0);
                $table->unsignedInteger('imported_messages')->default(0);
                $table->unsignedInteger('duplicate_rows')->default(0);
                $table->unsignedInteger('contacts_created')->default(0);
                $table->unsignedInteger('contacts_matched')->default(0);
                $table->unsignedInteger('rolled_back_messages')->default(0);

                $table->unsignedInteger('error_count')->default(0);
                $table->json('errors')->nullable();
                $table->string('errors_file_path', 500)->nullable();
                $table->text('error_message')->nullable();
                $table->json('ignored_columns')->nullable();

                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['organization_id', 'status'], 'idx_chat_imports_org_status');
                $table->index(['organization_id', 'created_at'], 'idx_chat_imports_org_created');
            });
        }

        // ما أنشأه الاستيراد وما طابقه: به يعرف التراجع ما يحذفه وما يتركه.
        if (!Schema::hasTable('chat_import_contacts')) {
            Schema::create('chat_import_contacts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('chat_import_id');
                $table->unsignedBigInteger('contact_id');
                $table->boolean('contact_created')->default(false);
                $table->boolean('ticket_created')->default(false);

                $table->unique(['chat_import_id', 'contact_id'], 'uniq_chat_import_contact');
                $table->index('contact_id', 'idx_chat_import_contacts_contact');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_import_contacts');
        Schema::dropIfExists('chat_imports');
    }
};
