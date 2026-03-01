<?php

namespace App\Models;

use App\Helpers\DateTimeHelper;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ChatTicket extends Model {
    use HasFactory;

    protected $guarded = [];
    public $timestamps = false;

    protected static function booted(): void
    {
        // عند إنشاء تذكرة جديدة: هذه تكون الأحدث (is_latest = true) والباقي لنفس الـ contact = false
        static::created(function (ChatTicket $ticket) {
            DB::table('chat_tickets')
                ->where('contact_id', $ticket->contact_id)
                ->where('id', '!=', $ticket->id)
                ->update(['is_latest' => false]);
            DB::table('chat_tickets')
                ->where('id', $ticket->id)
                ->update(['is_latest' => true]);
        });

        // عند حذف تذكرة كانت الأحدث: جعل التذكرة التالية (أعلى id المتبقية) هي الأحدث
        static::deleting(function (ChatTicket $ticket) {
            if (!$ticket->is_latest) {
                return;
            }
            $nextId = DB::table('chat_tickets')
                ->where('contact_id', $ticket->contact_id)
                ->where('id', '!=', $ticket->id)
                ->orderByDesc('id')
                ->value('id');
            if ($nextId !== null) {
                DB::table('chat_tickets')->where('id', $nextId)->update(['is_latest' => true]);
            }
        });
    }

    public function getCreatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getUpdatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function user(){
        return $this->belongsTo(User::class, 'assigned_to', 'id');
    }
}
