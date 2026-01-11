<?php

namespace App\Models;

use App\Helpers\DateTimeHelper;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Contact extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $guarded = [];
    protected $appends = ['full_name', 'formatted_phone_number'];
    protected $dates = ['deleted_at'];
    public $timestamps = false;

    public function getCreatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getUpdatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getAllContacts($organizationId, $searchTerm)
    {
        return $this->with('contactGroups')
            ->where('organization_id', $organizationId)
            ->where('deleted_at', null)
            ->where(function ($query) use ($searchTerm) {
                $query->where('contacts.first_name', 'like', '%' . $searchTerm . '%')
                ->orWhere('contacts.last_name', 'like', '%' . $searchTerm . '%')
                
                // Split the search term into parts and check for matches in both columns
                ->orWhere(function ($subQuery) use ($searchTerm) {
                    $searchParts = explode(' ', $searchTerm);
                    if (count($searchParts) > 1) {
                        $subQuery->where('contacts.first_name', 'like', '%' . $searchParts[0] . '%')
                                ->where('contacts.last_name', 'like', '%' . $searchParts[1] . '%');
                    }
                })
                
                // Match phone or email
                ->orWhere('contacts.phone', 'like', '%' . $searchTerm . '%')
                ->orWhere('contacts.email', 'like', '%' . $searchTerm . '%');
            })
            ->orderByDesc('is_favorite')
            ->latest()
            ->orderBy('id')
            ->paginate(10);
    }

    public function getAllContactGroups($organizationId)
    {
        return ContactGroup::where('organization_id', $organizationId)->whereNull('deleted_at')->get();
    }

    public function countContacts($organizationId)
    {
        return $this->where('organization_id', $organizationId)->whereNull('deleted_at')->count();
    }

    public function contactGroups()
    {
        return $this->belongsToMany(ContactGroup::class, 'contact_contact_group', 'contact_id', 'contact_group_id')
            ->using(ContactContactGroup::class)
            ->withTimestamps();
    }

    public function notes()
    {
        return $this->hasMany(ChatNote::class, 'contact_id')->orderBy('created_at', 'desc');
    }

    public function chats()
    {
        return $this->hasMany(Chat::class, 'contact_id')
        ->whereNull('deleted_at')
        ->orderBy('created_at', 'asc');
    }

    public function lastChat()
    {
        return $this->hasOne(Chat::class, 'contact_id')
            ->whereNull('deleted_at')
			->whereHas('chatLog')
            ->with('media')
            ->latest();
    }

    public function lastInboundChat()
    {
        return $this->hasOne(Chat::class, 'contact_id')
                    ->where('type', 'inbound')
                    ->whereNull('deleted_at')
                    ->with('media')
                    ->latest();
    }

    public function chatLogs()
    {
        return $this->hasMany(ChatLog::class);
    }
    public function contactsWithChatsOptimized(
        $organizationId,
        $searchTerm = null,
        $ticketingActive = false,
        $ticketState = null,
        $sortDirection = 'desc',
        $role = 'owner',
        $allowAgentsViewAllChats = true
 	   ) {
        $query = $this->newQuery()
            ->select([
                'contacts.id',
                'contacts.uuid',
                'contacts.first_name',
                'contacts.last_name',
                'contacts.phone',
                'contacts.email',
                'contacts.organization_id',
                'contacts.latest_chat_created_at',
                'contacts.is_blocked',
                'contacts.is_favorite',
            ])
			// ->where('contacts.id', 142069)
            ->where('contacts.organization_id', $organizationId)
            ->whereNotNull('contacts.latest_chat_created_at')
            ->whereNull('contacts.deleted_at');

        // ✅ استخدام العمود الموجود بدلاً من Subquery!
        // بدلاً من: selectSub(function($subquery) { ... })
        // نستخدم: العمود latest_chat_created_at الموجود أصلاً!

        $query->addSelect([
        DB::raw('(SELECT COUNT(*) 
                  FROM chats 
                  WHERE chats.contact_id = contacts.id 
                  AND chats.type = "inbound" 
                  AND chats.is_read = 0 
                  AND chats.deleted_at IS NULL) as unread_messages_count')
    	]);
    
        // ✅ Eager load بشكل محسّن
        $query->with([
            'lastChat' => function ($q) {
                $q->selectRaw('*')->whereNull('deleted_at');
            },
           'lastInboundChat' => function ($q) {
               $q->selectRaw('*')
                 ->where('type', 'inbound')
                 ->whereNull('deleted_at');
           }
        ])->when(Request()->has('is_read'),function($q){
			$q->whereHas('lastInboundChat',function($q){
			$q->where('is_read',0);
		});
		});

        // ✅ شروط التذاكر مع JOIN محسّن
        if ($ticketingActive) {
            $query->leftJoin('chat_tickets', function ($join) {
                $join->on('contacts.id', '=', 'chat_tickets.contact_id');
            });

            // إضافة أعمدة التذكرة
            $query->addSelect([
                'chat_tickets.status as ticket_status',
                'chat_tickets.assigned_to as ticket_assigned_to'
            ]);

            // فلترة حسب الحالة
            if ($ticketState === 'unassigned') {
                $query->whereNull('chat_tickets.assigned_to');
            } elseif ($ticketState !== null && $ticketState !== 'all') {
                $query->where('chat_tickets.status', $ticketState);
            }

            // صلاحيات الوكلاء
            if ($role === 'agent' && !$allowAgentsViewAllChats) {
                $query->where('chat_tickets.assigned_to', auth()->id());
            }
        }

        // ✅ البحث - محسّن
        if ($searchTerm) {
            $query->where(function ($q) use ($searchTerm) {
                $q->where('contacts.first_name', 'like', "%{$searchTerm}%")
                  ->orWhere('contacts.last_name', 'like', "%{$searchTerm}%")
                  ->orWhere('contacts.phone', 'like', "%{$searchTerm}%")
                  ->orWhere('contacts.email', 'like', "%{$searchTerm}%")
                  ->orWhereRaw(
                      "CONCAT(contacts.first_name, ' ', contacts.last_name) LIKE ?",
                      ["%{$searchTerm}%"]
                  );
            });
        }

        // ✅ الترتيب باستخدام العمود الموجود
        $query->orderBy('contacts.latest_chat_created_at', $sortDirection);

        // ✅ Pagination
        return $query->paginate(20); // زيادة العدد لتقليل الطلبات
    }

    

    public function getFirstNameAttribute()
    {
        $firstName = $this->attributes['first_name'];
        $firstName = $this->decodeUnicodeBytes($firstName);

        return $firstName;
    }

    public function getLastNameAttribute()
    {
        $lastName = $this->attributes['last_name'];
        $lastName = $this->decodeUnicodeBytes($lastName);

        return $lastName;
    }

    public function getFullNameAttribute()
    {
        $firstName = $this->attributes['first_name'];
        $lastName = $this->attributes['last_name'];

        // Convert byte sequences to Unicode characters
        $firstName = $this->decodeUnicodeBytes($firstName);
        $lastName = $this->decodeUnicodeBytes($lastName);

        // Return the full name combining first name and last name
        return $firstName . ' ' . $lastName;

        //return "{$this->first_name} {$this->last_name}";
    }

    public function getFormattedPhoneNumberAttribute($value)
    {
        $phone = $this->phone;

        // Only format if the phone number starts with '+'
        if (strpos($phone, '+') === 0) {
            try {
                return phone($phone)->formatInternational();
            } catch (\Exception $e) {
                // Fallback: return the raw phone if formatting fails
                return $phone;
            }
        }

        // If not international, just return as-is
        return $phone;
    }

    protected function decodeUnicodeBytes($value)
    {
        return preg_replace_callback('/\\\\x([0-9A-F]{2})/i', function ($matches) {
            return chr(hexdec($matches[1]));
        }, $value);
    }
	public function markAsBlocked():void
	{
		$this->is_blocked = true ;
		$this->save() ;
	}
	public function markAsUnBlocked():void
	{
		$this->is_blocked = false ;
		$this->save() ;
	}
	public function encryptPhoneNumber(bool $phoneMustBeEncrypted):string
	{
		if(!$phoneMustBeEncrypted){
			return 	$this->formatted_phone_number ;
		}
		$mask =Str::mask($this->phone, '*', 4);
		$this->phone= $mask;
		return $this->formatted_phone_number= $mask;
	}
	public static function currentUserIsAgent()
	{
		$organizationId = session()->get('current_organization');
		 $team = Team::where('organization_id', $organizationId)->where('user_id', auth()->user()->id)->first();
		 return $team->role === 'agent';
	}
	public static function contactPhoneNumberShouldEncrypted(?Organization $organization = null):bool
	{
			 $isAgent = self::currentUserIsAgent();
			$encryptContactsForAgents = self::getTicketSettings($organization);
			return $isAgent && $encryptContactsForAgents ;
	}
	private static function getTicketSettings(?Organization $organization = null){
        // Retrieve the settings for the current organization
        $settings = $organization?: Organization::where('id', session()->get('current_organization'))->first();

        if ($settings) {
            // Decode the JSON metadata column into an associative array
            $metadata = json_decode($settings->metadata, true);

            if (isset($metadata['tickets'])) {
                // If the 'contacts' key exists, retrieve the 'location' value
                $encryptContactsForAgents = $metadata['tickets']['encrypt_contacts_for_agents'];

                // Now, you have the location value available
                return $encryptContactsForAgents;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

}
