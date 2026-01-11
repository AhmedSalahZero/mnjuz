<?php

namespace App\Models;
use App\Helpers\DateTimeHelper;
use App\Models\Chat;
use App\Models\ChatNote;
use App\Models\ChatTicket;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatLog extends Model {
    use HasFactory;

    protected $guarded = [];
    public $timestamps = false;

    // Accessor to format created_at with organization's timezone
    public function getCreatedAtAttribute($value)
    {
        // Convert the stored UTC timestamp to the organization's timezone
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getUpdatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function entity()
    {
        return $this->morphTo('entity');
    }

    public function getRelatedEntitiesAttribute()
    {
        $entityType = $this->entity_type;
        $entityId = $this->entity_id;
        $relatedEntity = null;

        switch ($entityType) {
            case 'chat':
                $relatedEntity = Chat::with('media', 'user', 'logs')->find($entityId);
				// logger('chat log entity id'.$entityId);
				// logger('current created at'.$relatedEntity->created_at);
                break;
            case 'ticket':
                $relatedEntity = ChatTicketLog::find($entityId);
                break;
            case 'notes':
                $relatedEntity = ChatNote::find($entityId);
                break;
        }

        return $relatedEntity;
    }
}
