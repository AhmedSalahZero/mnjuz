<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نسخنا من وسائط بطاقات قالب الـ carousel.
 *
 * عمود مستقل لا داخل metadata: المزامنة مع Meta تكتب metadata من جديد
 * بكائن Meta، وروابط scontent فيه تنتهي صلاحيتها.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('templates', 'carousel_media')) {
            Schema::table('templates', function (Blueprint $table) {
                $table->text('carousel_media')->nullable()->after('metadata');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('templates', 'carousel_media')) {
            Schema::table('templates', function (Blueprint $table) {
                $table->dropColumn('carousel_media');
            });
        }
    }
};
