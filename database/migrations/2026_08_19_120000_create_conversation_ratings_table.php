<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تقييم العميل لمستوى الخدمة بعد إغلاق المحادثة.
 *
 * الصفّ يُنشأ لحظة الإغلاق بحالة pending ومعه رمز الرابط، ثم يُملأ حين
 * يفتح العميل الرابط ويرسل النموذج. نحفظ اسم العميل ورقمه لحظة الإرسال
 * لقطةً ثابتة: تعديل جهة الاتصال أو حذفها لاحقاً يجب ألّا يغيّر تقييماً
 * مضى، ولا أن يُسقطه من التقارير.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_ratings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();

            // لقطات ثابتة لا تتبع تعديلات المصدر
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('agent_name')->nullable();

            // رمز الرابط: عشوائي، فريد، يُستهلك مرّة واحدة
            $table->string('token', 64)->unique();

            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('comment')->nullable();

            // pending = أُرسل ولم يُجَب · submitted = قُيّم
            $table->string('status', 16)->default('pending');

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('ip', 45)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // الاستعلام السائد: تقييمات منظمة مرتّبة بالأحدث
            $table->index(['organization_id', 'status', 'id']);
            $table->index(['organization_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_ratings');
    }
};
