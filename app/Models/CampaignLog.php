<?php

namespace App\Models;

use App\Helpers\DateTimeHelper;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Prunable;

class CampaignLog extends Model {
    use HasFactory, Prunable;

    protected $guarded = [];

    /**
     * Get the prunable model query (records older than 3 days).
     */
    // public function prunable(): Builder
    // {
    //     return static::query()->where('created_at', '<=', now()->subDays(14));
    // }
    public $timestamps = true;

    public function getCreatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getUpdatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function campaign(): BelongsTo {
        return $this->belongsTo(Campaign::class, 'campaign_id', 'id');
    }

    public function contact(): BelongsTo {
        return $this->belongsTo(Contact::class, 'contact_id', 'id');
    }

    public function chat(): BelongsTo {
        return $this->belongsTo(Chat::class, 'chat_id', 'id');
    }

    public function retries(): HasMany {
        return $this->hasMany(CampaignLogRetry::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CampaignMessageAttempt::class)->orderBy('attempt_number');
    }
}
