<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ما فعله الزائر قبل أن يكتب: «عندي سؤال» أو «عندي مشكلة»، والأسئلة
 * الشائعة التي قرأها، وهل لم يجد إجابته فيها. يراه الموظّف في كارت الزائر.
 *
 * عمود في آخر الجدول بلا after(): MySQL 8 يضيفه فورياً (INSTANT).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('webchat_visitors') || Schema::hasColumn('webchat_visitors', 'journey')) {
            return;
        }

        Schema::table('webchat_visitors', function (Blueprint $table) {
            $table->text('journey')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('webchat_visitors', 'journey')) {
            Schema::table('webchat_visitors', function (Blueprint $table) {
                $table->dropColumn('journey');
            });
        }
    }
};
