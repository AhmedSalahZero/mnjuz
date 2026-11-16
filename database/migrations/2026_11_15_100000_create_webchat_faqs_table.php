<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الأسئلة الشائعة في المحادثة المباشرة: يكتبها التاجر، ويقرؤها الزائر داخل
 * الـ widget قبل أن يفتح محادثة («عندي سؤال»).
 *
 * جدول جديد لا يمسّ شيئاً قائماً. الحذف نهائي: ما قرأه الزائر يُحفظ نسخةً
 * نصّية في webchat_visitors.journey فلا يحتاج الصفّ بعد حذفه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('webchat_faqs')) {
            return;
        }

        Schema::create('webchat_faqs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('question_ar', 150);
            $table->string('question_en', 150)->nullable();
            $table->text('answer_ar')->nullable();
            $table->text('answer_en')->nullable();
            // صورة الإجابة: مسار محلّي (public/webchat-faqs/{org}/…) أو رابط S3.
            $table->string('image_path', 255)->nullable();
            $table->string('image_url', 1024)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'is_active', 'position'], 'webchat_faqs_org_active_position_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webchat_faqs');
    }
};
