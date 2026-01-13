<?php

namespace App\Models;

use App\Classes\LeadrModel;
use App\Traits\HasOrganization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamWorkingHour extends LeadrModel
{
    use HasOrganization;

    protected $fillable = [
        'team_id',
        'organization_id',
        'day_of_week',
        'start_time',
        'end_time',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
