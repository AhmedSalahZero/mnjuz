<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مزوّد البثّ لكل منشأة: pusher أو reverb، وnull = افتراضي المنصّة.
 *
 * كان مزوّداً واحداً للمنصّة كلها (صفّ broadcast_provider). الآن يختار الأدمن
 * لكل منشأة، والمنشآت التي لم يُختَر لها شيء تتبع الافتراضي — فنشر هذا الترحيل
 * لا يُبدّل شيئاً لأحد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('organizations') || Schema::hasColumn('organizations', 'broadcast_provider')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('broadcast_provider', 16)->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('organizations') || !Schema::hasColumn('organizations', 'broadcast_provider')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('broadcast_provider');
        });
    }
};
