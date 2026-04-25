<?php

namespace App\Models;

use App\Helpers\DateTimeHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CampaignMessageAttempt extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function getCreatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getUpdatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getExecutedAtAttribute($value)
    {
        if (!$value) {
            return null;
        }

        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function campaignLog()
    {
        return $this->belongsTo(CampaignLog::class, 'campaign_log_id');
    }
}
