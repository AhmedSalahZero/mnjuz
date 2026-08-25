<?php

namespace App\Models;

use App\Helpers\DateTimeHelper;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Chat extends Model {
    use HasFactory;
    use HasUuid;

    protected $guarded = [];
    public $timestamps = false;

    protected static function boot()
    {
        parent::boot();

        static::created(function ($chat) {
            $contact = $chat->contact;
            if ($contact) {
                $createdAtUtc = $chat->getAttributes()['created_at'] ?? now()->utc()->toDateTimeString();

                $contact->latest_chat_created_at = $createdAtUtc;
                if ($chat->type === 'inbound') {
                    $contact->last_inbound_chat_created_at = $createdAtUtc;
                }
                $contact->save();
            }
        });

        /*static::updated(function ($chat) {
            $contact = $chat->contact;
            if ($contact) {
                $latestChat = Chat::where('contact_id', $contact->id)->orderBy('created_at', 'desc')->first();
                $contact->latest_chat_created_at = $latestChat ? $latestChat->created_at : null;
                $contact->save();
            }
        });*/
    }
    
    public function getCreatedAtAttribute($value)
    {
		
        return DateTimeHelper::toOrganizationTimeString($value,$this->attributes['organization_id']);
    }
    
    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id', 'id');
    }

    public function media()
    {
        return $this->belongsTo(ChatMedia::class, 'media_id', 'id');
    }

    public function logs()
    {
        return $this->hasMany(ChatStatusLog::class, 'chat_id', 'id');
    }
	public function chatLog()
	{
		return $this->hasOne(ChatLog::class, 'entity_id', 'id')->where('entity_type', 'chat');
	}
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
