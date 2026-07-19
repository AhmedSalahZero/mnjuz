<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الموبايل والـ Web يجب أن يكونا مستقلين.
 * نضيف عمود device_category (web | mobile) ونغيّر الـ unique
 * من unique(user_id) إلى unique(user_id, device_category).
 *
 * device_type = 'mobile' → device_category = 'mobile'
 * device_type = 'desktop' | 'tablet' | null → device_category = 'web'
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            $table->string('device_category', 10)->default('web')->after('device_type');
        });

        // تحديث السجلات الموجودة
        DB::statement("
            UPDATE user_devices
            SET device_category = CASE
                WHEN device_type = 'mobile' THEN 'mobile'
                ELSE 'web'
            END
        ");

        // إزالة الـ foreign key أولاً ثم الـ unique القديم
        Schema::table('user_devices', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id']);
        });

        // إضافة الـ unique الجديد على (user_id, device_category) وإعادة الـ foreign key
        Schema::table('user_devices', function (Blueprint $table) {
            $table->unique(['user_id', 'device_category'], 'user_devices_user_category_unique');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            $table->dropUnique('user_devices_user_category_unique');
        });

        Schema::table('user_devices', function (Blueprint $table) {
            $table->unique('user_id');
            $table->dropColumn('device_category');
        });
    }
};
