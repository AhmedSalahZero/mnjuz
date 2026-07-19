<?php

namespace App\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CampaignMediaHistory extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = 'campaign_media_history';

    protected $guarded = [];
    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
