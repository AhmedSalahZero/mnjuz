<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قوالب محفوظة لقنوات بلا موافقة (تيليجرام، البريد، وSMS لاحقاً).
 *
 * جدول جديد لا عمود في templates: ذاك مرآة لقوالب Meta — يُحذف كلّه حين
 * تُفصل واتساب، وأكثر من ثمانية استعلامات «APPROVED» تغذّي منه مستهلكين
 * لواتساب وحدها (ومنها تطبيق الجوال)، و meta_id فيه إلزامي. هنا لا يمسّ
 * شيئاً قائماً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('channel_templates')) {
            return;
        }

        Schema::create('channel_templates', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->unsignedBigInteger('organization_id');
            $table->string('channel', 20);
            $table->string('name', 191);
            // موضوع البريد؛ null لتيليجرام.
            $table->string('subject', 255)->nullable();
            $table->text('body');
            // مرفق تيليجرام: {url, name, type, mime_type, size, location}.
            $table->text('media')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'channel', 'deleted_at'], 'channel_templates_org_channel_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_templates');
    }
};
