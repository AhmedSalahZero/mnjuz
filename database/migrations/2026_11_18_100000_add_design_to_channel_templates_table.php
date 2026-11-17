<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تصميم قالب البريد: عناصر الـ builder (ترويسة، عنوان، نص، صورة، زر…)
 * JSON. يبقى body النسخة النصّية المولَّدة منه — للقائمة، ولجزء text/plain
 * في الرسالة، ولمن يقرأ الحمولة القديمة.
 *
 * MEDIUMTEXT لا TEXT: نصوص العناصر وروابطها قد تتجاوز 64KB بالعربية.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('channel_templates') || Schema::hasColumn('channel_templates', 'design')) {
            return;
        }

        Schema::table('channel_templates', function (Blueprint $table) {
            $table->mediumText('design')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('channel_templates', 'design')) {
            Schema::table('channel_templates', function (Blueprint $table) {
                $table->dropColumn('design');
            });
        }
    }
};
