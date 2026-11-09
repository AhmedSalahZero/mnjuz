<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مستخدمٌ سجّل برابط إحالة مستخدم آخر.
 */
class Referral extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id')->withTrashed();
    }
}
