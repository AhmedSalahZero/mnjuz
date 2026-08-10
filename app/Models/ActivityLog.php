<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $table = 'activity_logs';

    /** الصفّ يُكتب مرّة ولا يُعدَّل، فلا حاجة لـ updated_at. */
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'properties' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
