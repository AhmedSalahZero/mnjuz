<?php

namespace Modules\Webhook\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $fillable = ['webhook_id', 'event'];

    public function webhook()
    {
        return $this->belongsTo(Webhook::class);
    }
}
