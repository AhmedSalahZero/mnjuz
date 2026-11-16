<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * كوبون «إضافة دفعة» وعدّ الاستخدام.
 *
 * applies_to: أين ينطبق الكوبون (plans | topup | both). كل كوبون قائم يبقى
 * «plans» — ما كان عليه قبل الشحن بكوبون؛ الأدمن يوسّعه إن أراد.
 *
 * coupon_redemptions: كل استخدام بمنشأته — منه «مرّة لكل منشأة». صفّ الشحن
 * يُنشأ «pending» عند التحويل إلى البوّابة ويُسوّى «redeemed» بعد تأكيد الدفع
 * مرّة واحدة (callback وwebhook لا يُضاعفان الخصم). لا تعبئة رجعية: العدّ
 * يبدأ من النشر.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('coupons', 'applies_to')) {
            if (DB::getDriverName() === 'mysql') {
                // INSTANT: عمود بقيمة افتراضية بلا إعادة بناء الجدول.
                DB::statement("ALTER TABLE coupons ADD COLUMN applies_to VARCHAR(10) NOT NULL DEFAULT 'plans' AFTER percentage, ALGORITHM=INSTANT");
            } else {
                Schema::table('coupons', function (Blueprint $table) {
                    $table->string('applies_to', 10)->default('plans');
                });
            }
        }

        if (!Schema::hasTable('coupon_redemptions')) {
            Schema::create('coupon_redemptions', function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique();
                $table->unsignedBigInteger('coupon_id');
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('context', 10); // plan | topup
                $table->string('status', 10)->default('pending'); // pending | redeemed
                $table->decimal('amount', 13, 2)->default(0);
                $table->decimal('charged_amount', 13, 2)->default(0);
                $table->decimal('discount_amount', 13, 2)->default(0);
                $table->unsignedBigInteger('billing_payment_id')->nullable();
                $table->unsignedBigInteger('billing_invoice_id')->nullable();
                $table->timestamp('redeemed_at')->nullable();
                $table->timestamps();

                $table->index(['coupon_id', 'organization_id', 'status'], 'coupon_redemptions_coupon_org_status_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');

        if (Schema::hasColumn('coupons', 'applies_to')) {
            Schema::table('coupons', function (Blueprint $table) {
                $table->dropColumn('applies_to');
            });
        }
    }
};
