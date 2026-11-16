<?php

namespace App\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** استخدام كوبون: حجزٌ عند التحويل إلى البوّابة، ثم تسوية بعد الدفع. */
class CouponRedemption extends Model
{
    use HasUuid;

    public const CONTEXT_PLAN = 'plan';
    public const CONTEXT_TOPUP = 'topup';

    public const STATUS_PENDING = 'pending';
    public const STATUS_REDEEMED = 'redeemed';

    protected $guarded = [];

    protected $casts = [
        'redeemed_at' => 'datetime',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
