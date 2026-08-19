<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConversationRating extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUBMITTED = 'submitted';

    /** صلاحية الرابط بالأيام — تُعرض للمستخدم ولا تُكرَّر رقماً في الكود. */
    public const LINK_VALID_DAYS = 7;


    protected $guarded = [];

    protected $casts = [
        'rating' => 'integer',
        'sent_at' => 'datetime',
        'submitted_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    /**
     * الرابط صالح إن لم يُستهلك ولم تنتهِ مدّته.
     * غياب expires_at يعني «بلا انتهاء» لا «منتهٍ».
     */
    public function isOpenForSubmission(): bool
    {
        if ($this->isSubmitted()) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return !$this->isSubmitted() && $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }
}
