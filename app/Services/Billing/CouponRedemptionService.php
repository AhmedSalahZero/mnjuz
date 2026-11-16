<?php

namespace App\Services\Billing;

use App\Models\BillingCredit;
use App\Models\BillingInvoice;
use App\Models\BillingTransaction;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * الكوبونات: من يستعملها وأين، وعدّ استخدامها.
 *
 * القواعد (قرار المالك):
 *  - الكوبون مرّة واحدة لكل منشأة، والعدد الكلّي (quantity) يُحسب فعلاً —
 *    كان quantity_redeemed لا يزيد في أيّ مسار حيّ، فيُستعمل كل كوبون بلا حدّ.
 *  - applies_to يحدّد أين ينطبق: الباقات، «إضافة دفعة»، أو الاثنان.
 *  - في «إضافة دفعة»: يُدفع المبلغ ناقصاً الخصم، ويُضاف الخصم إلى الرصيد سطراً
 *    منفصلاً بعد تأكيد الدفع — فيزيد الرصيد بالمبلغ كاملاً.
 */
class CouponRedemptionService
{
    public const APPLIES_PLANS = 'plans';
    public const APPLIES_TOPUP = 'topup';
    public const APPLIES_BOTH = 'both';

    public const APPLIES_TO = [self::APPLIES_PLANS, self::APPLIES_TOPUP, self::APPLIES_BOTH];

    /**
     * الكوبون إن صلح لهذه المنشأة في هذا السياق، أو سبب رفضه.
     *
     * @return array{coupon: ?Coupon, error: ?string}
     */
    public function findUsable(?string $code, int $organizationId, string $context): array
    {
        $code = trim((string) $code);

        if ($code === '') {
            return ['coupon' => null, 'error' => __('The coupon code is invalid!')];
        }

        $coupon = Coupon::where('code', $code)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();

        if (!$coupon) {
            return ['coupon' => null, 'error' => __('The coupon code is invalid!')];
        }

        if (!self::appliesTo($coupon, $context)) {
            return ['coupon' => null, 'error' => $context === CouponRedemption::CONTEXT_TOPUP
                ? __('This coupon cannot be used when adding a payment.')
                : __('This coupon cannot be used for plans.')];
        }

        if (self::isExhausted($coupon)) {
            return ['coupon' => null, 'error' => __('The coupon has expired!')];
        }

        if ($this->usedBy($coupon, $organizationId)) {
            return ['coupon' => null, 'error' => __('Your account has already used this coupon.')];
        }

        return ['coupon' => $coupon, 'error' => null];
    }

    public static function appliesTo(Coupon $coupon, string $context): bool
    {
        $scope = $coupon->applies_to ?: self::APPLIES_PLANS;

        return $scope === self::APPLIES_BOTH
            || ($context === CouponRedemption::CONTEXT_PLAN && $scope === self::APPLIES_PLANS)
            || ($context === CouponRedemption::CONTEXT_TOPUP && $scope === self::APPLIES_TOPUP);
    }

    /** نفد العدد؟ quantity فارغة = بلا حدّ. */
    public static function isExhausted(Coupon $coupon): bool
    {
        return $coupon->quantity !== null && (int) $coupon->quantity_redeemed >= (int) $coupon->quantity;
    }

    public function usedBy(Coupon $coupon, int $organizationId): bool
    {
        return CouponRedemption::where('coupon_id', $coupon->id)
            ->where('organization_id', $organizationId)
            ->where('status', CouponRedemption::STATUS_REDEEMED)
            ->exists();
    }

    /** النسبة بين 0 و100: قيمة أدخلها الأدمن خارجها (أو سالبة) لا ترفع السعر. */
    public static function percentage(Coupon $coupon): float
    {
        return max(0.0, min(100.0, (float) $coupon->percentage));
    }

    /**
     * خصم الشحن: يُدفع charge ويُضاف discount إلى الرصيد.
     *
     * @return array{amount: float, discount: float, charge: float}
     */
    public function quoteTopup(Coupon $coupon, float $amount): array
    {
        $amount = round($amount, 2);
        $charge = round($amount * (1 - self::percentage($coupon) / 100), 2);

        return [
            'amount' => $amount,
            'discount' => round($amount - $charge, 2),
            'charge' => $charge,
        ];
    }

    /** حجز الكوبون لدفعة شحن: مرجعه (uuid) يسافر مع البوّابة. */
    public function reserveTopup(Coupon $coupon, int $organizationId, ?int $userId, array $quote): CouponRedemption
    {
        return CouponRedemption::create([
            'coupon_id' => $coupon->id,
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'context' => CouponRedemption::CONTEXT_TOPUP,
            'status' => CouponRedemption::STATUS_PENDING,
            'amount' => $quote['amount'],
            'charged_amount' => $quote['charge'],
            'discount_amount' => $quote['discount'],
        ]);
    }

    /**
     * تسوية حجز الشحن بعد تأكيد الدفع: خصمٌ إلى الرصيد مرّة واحدة.
     *
     * يُنادى من داخل معاملة البوّابة، وقبل تفعيل الاشتراك من الرصيد — فاشتراك
     * منتهٍ يتجدّد من المدفوع والخصم معاً. والقفل على الحجز يجعل وصول التأكيد
     * مرّتين (العودة من البوّابة والـ webhook) خصماً واحداً.
     *
     * حجزٌ تأكّد بعد نفاد العدد، أو بعد استعمال موازٍ لنفس المنشأة، يُكرَّم:
     * العميل دفع السعر المخفَّض فعلاً. ويُسجَّل للمراجعة.
     */
    public function settleTopup(?string $reference, int $organizationId, ?int $billingPaymentId, float $paidAmount, ?int $userId = null): ?BillingTransaction
    {
        $reference = trim((string) $reference);

        if ($reference === '' || $organizationId <= 0) {
            return null;
        }

        return DB::transaction(function () use ($reference, $organizationId, $billingPaymentId, $paidAmount, $userId) {
            $redemption = CouponRedemption::where('uuid', $reference)->lockForUpdate()->first();

            if (!$redemption
                || $redemption->status !== CouponRedemption::STATUS_PENDING
                || $redemption->context !== CouponRedemption::CONTEXT_TOPUP
                || (int) $redemption->organization_id !== $organizationId) {
                return null;
            }

            // المدفوع غير ما حُجز له: لا خصم (لا نعرف ما الذي دُفع).
            if (abs(round($paidAmount, 2) - (float) $redemption->charged_amount) > 0.01) {
                Log::error('Coupon top-up settled with an unexpected paid amount', [
                    'redemption_id' => $redemption->id,
                    'organization_id' => $organizationId,
                    'expected' => (float) $redemption->charged_amount,
                    'paid' => round($paidAmount, 2),
                ]);

                return null;
            }

            $coupon = Coupon::whereKey($redemption->coupon_id)->lockForUpdate()->first();

            if ($coupon && (self::isExhausted($coupon) || $this->usedBy($coupon, $organizationId))) {
                Log::warning('Coupon top-up honoured after the limit was reached', [
                    'redemption_id' => $redemption->id,
                    'coupon_id' => $coupon->id,
                    'organization_id' => $organizationId,
                ]);
            }

            $discount = (float) $redemption->discount_amount;
            $transaction = null;

            if ($discount > 0) {
                $description = 'Coupon discount: ' . ($coupon->code ?? '');

                $credit = BillingCredit::create([
                    'organization_id' => $organizationId,
                    'description' => $description,
                    'amount' => $discount,
                ]);

                $transaction = BillingTransaction::create([
                    'organization_id' => $organizationId,
                    'entity_type' => 'credit',
                    'entity_id' => $credit->id,
                    'description' => $description,
                    'amount' => $discount,
                    'created_by' => $userId ?? $redemption->user_id,
                ]);
            }

            $redemption->forceFill([
                'status' => CouponRedemption::STATUS_REDEEMED,
                'billing_payment_id' => $billingPaymentId,
                'redeemed_at' => now(),
            ])->save();

            if ($coupon) {
                Coupon::whereKey($coupon->id)->update(['quantity_redeemed' => DB::raw('COALESCE(quantity_redeemed, 0) + 1')]);
            }

            return $transaction;
        });
    }

    /** فاتورة باقة بكوبون: يُسجَّل الاستخدام ويُعدّ — داخل معاملة الفاتورة. */
    public function recordPlanRedemption(int $couponId, int $organizationId, ?int $userId, BillingInvoice $invoice, float $discount): void
    {
        CouponRedemption::create([
            'coupon_id' => $couponId,
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'context' => CouponRedemption::CONTEXT_PLAN,
            'status' => CouponRedemption::STATUS_REDEEMED,
            'amount' => (float) $invoice->subtotal,
            'charged_amount' => (float) $invoice->total,
            'discount_amount' => round($discount, 2),
            'billing_invoice_id' => $invoice->id,
            'redeemed_at' => now(),
        ]);

        Coupon::whereKey($couponId)->update(['quantity_redeemed' => DB::raw('COALESCE(quantity_redeemed, 0) + 1')]);
    }
}
