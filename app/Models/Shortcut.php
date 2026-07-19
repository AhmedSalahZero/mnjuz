<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Shortcut extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'organization_id',
        'user_id',
        'scope',
        'command',
        'message',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (Shortcut $shortcut) {
            if (empty($shortcut->uuid)) {
                $shortcut->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * الاختصارات المتاحة للموظف داخل المحادثة: الخاصة به + اختصارات الشركة.
     */
    public function scopeAvailableFor($query, int $organizationId, int $userId)
    {
        return $query->where('organization_id', $organizationId)
            ->where(function ($q) use ($userId) {
                $q->where('scope', 'company')
                    ->orWhere(function ($inner) use ($userId) {
                        $inner->where('scope', 'personal')->where('user_id', $userId);
                    });
            });
    }
}
