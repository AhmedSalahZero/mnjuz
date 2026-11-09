<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «ادعُ صديقاً»: لكل مستخدم رمز إحالة، ولكل من سجّل برابطه صفّ واحد.
     *
     * الرمز على المستخدم لا على المنشأة: الدعوة شخصية، ومن يدير منشأتين
     * يشارك رابطاً واحداً. وreferred_user_id فريد: المستخدم يُحال مرّة واحدة
     * في عمره — أوّل رابط سجّل به.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'referral_code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('referral_code', 16)->nullable()->unique()->after('waz_contact_id');
            });
        }

        if (!Schema::hasTable('referrals')) {
            Schema::create('referrals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('referrer_id');
                $table->unsignedBigInteger('referred_user_id')->unique();
                $table->string('code', 16);
                $table->timestamp('created_at')->nullable();

                $table->index(['referrer_id', 'created_at'], 'idx_referrals_referrer_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');

        if (Schema::hasColumn('users', 'referral_code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['referral_code']);
                $table->dropColumn('referral_code');
            });
        }
    }
};
