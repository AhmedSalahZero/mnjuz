<?php

namespace App\Models;
use App\Helpers\DateTimeHelper;
use App\Http\Traits\HasUuid;
use App\Services\PhoneService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'id');
    }

    public function contactGroups()
    {
        return $this->belongsToMany(ContactGroup::class, 'contact_contact_group', 'contact_id', 'contact_group_id')
            ->using(ContactContactGroup::class)
            ->withTimestamps();
    }

    public function contactCategories()
    {
        return $this->belongsToMany(ContactCategory::class, 'contact_category_contact', 'contact_id', 'contact_category_id')
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

    /**
     * آخر رسالة (مرتبطة بـ chat_log) — واحد فقط لكل contact عند Eager Load.
     * استخدام ofMany يمنع تحميل كل الـ chats ثم أخذ الأول (آلاف السجلات).
     * المعامل الثاني Closure يطبّق على الـ subquery؛ الثالث يجب أن يكون string (اسم العلاقة) أو null.
     */
    public function lastChat()
    {
        return $this->hasOne(Chat::class, 'contact_id')
            ->ofMany(['created_at' => 'max'], function (Builder $q) {
                $q->whereNull('deleted_at')->whereHas('chatLog');
            })
            ->with('media');
    }

    /**
     * آخر رسالة واردة — واحد فقط لكل contact عند Eager Load.
     */
    public function lastInboundChat()
    {
        return $this->hasOne(Chat::class, 'contact_id')
            ->ofMany(['created_at' => 'max'], function (Builder $q) {
                $q->whereNull('deleted_at')->where('type', 'inbound');
            })
            ->with('media');
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
        $allowAgentsViewAllChats = true,
        $eagerLoadCategories = false,
        $perPage = null,
        $page = 1,
        $categoryUuid = null,
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
                'contacts.last_inbound_chat_created_at',
                'contacts.is_blocked',
                'contacts.is_favorite',
                'contacts.avatar',
            ])
			// ->where('contacts.id', 142069)
            ->where('contacts.organization_id', $organizationId)
            ->whereNotNull('contacts.latest_chat_created_at')
            ->whereNull('contacts.deleted_at');

        if ($categoryUuid) {
            $categoryId = ContactCategory::query()
                ->where('organization_id', $organizationId)
                ->where('uuid', $categoryUuid)
                ->value('id');

            if ($categoryId) {
                $query->whereExists(function ($sub) use ($categoryId) {
                    $sub->selectRaw('1')
                        ->from('contact_category_contact')
                        ->whereColumn('contact_category_contact.contact_id', 'contacts.id')
                        ->where('contact_category_contact.contact_category_id', $categoryId);
                });
            } else {
                $query->whereRaw('0 = 1');
            }
        }

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
    
        // ✅ Eager load lastChat فقط؛ last_inbound_chat نعتمد على العمود last_inbound_chat_created_at
        $with = ['lastChat'];
        if ($eagerLoadCategories) {
            $with[] = 'contactCategories:id,name,uuid,background_color,text_color';
        }
        $query->with($with)
        ->when(Request()->has('is_read'), function ($q) {
            $q->whereExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('chats')
                    ->whereColumn('chats.contact_id', 'contacts.id')
                    ->where('chats.type', 'inbound')
                    ->where('chats.is_read', 0)
                    ->whereNull('chats.deleted_at');
            });
        });

        // ✅ شروط التذاكر — INNER JOIN عند الفلترة لبدء من chat_tickets المفهرسة
        if ($ticketingActive) {
            $isTicketFiltered = in_array($ticketState, ['open', 'closed', 'unassigned'], true);
            $joinMethod = $isTicketFiltered ? 'join' : 'leftJoin';

            $query->{$joinMethod}('chat_tickets', function ($join) use ($ticketState) {
                $join->on('contacts.id', '=', 'chat_tickets.contact_id')
                    ->where('chat_tickets.is_latest', true);

                if ($ticketState === 'unassigned') {
                    $join->whereNull('chat_tickets.assigned_to');
                } elseif (in_array($ticketState, ['open', 'closed'], true)) {
                    $join->where('chat_tickets.status', $ticketState);
                }
            });

            $query->addSelect([
                'chat_tickets.status as ticket_status',
                'chat_tickets.assigned_to as ticket_assigned_to',
            ]);

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
        $query->orderBy('contacts.latest_chat_created_at', $sortDirection)
		->orderBy('contacts.id', 'desc');
		/**
		 * @var Builder $query 
		 */
		if ($perPage !== null) {
			return $query->paginate($perPage, ['*'], 'contact_page', $page);
		}
		return $query->get();
		// return $query->paginate($perPage); // for web app pagination

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
        $stored = $this->attributes['formatted_phone'] ?? null;
        if ($stored !== null && $stored !== '') {
            return $stored;
        }

        $phone = $this->phone;
        if ($phone === null || $phone === '') {
            return $phone;
        }

        return PhoneService::formatForDisplay($phone);
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
	public function encryptPhoneNumber(bool $phoneMustBeEncrypted):?string
	{
		if(!$phoneMustBeEncrypted){
			return 	$this->formatted_phone_number?:$this->phone ;
		}
		$mask =Str::mask($this->phone, '*', 4);
		$this->phone= $mask;
		return $this->formatted_phone_number?:'-';
	}
	public static function currentUserIsAgent()
	{
		// تخزين مؤقت لكل طلب (نفس النتيجة لجميع جهات الاتصال في الصفحة)
		static $cached = null;
		if ($cached !== null) {
			return $cached;
		}
		$organizationId = session()->get('current_organization', Request()->get('organization_id'));
		$user = auth()->user();
		$team = null;
		if ($user && $organizationId) {
			$team = Team::where('organization_id', $organizationId)->where('user_id', $user->id)->first();
		}
		$cached = $team && $team->role === 'agent';
		return $cached;
	}

	public static function contactPhoneNumberShouldEncrypted(?Organization $organization = null):bool
	{
		// تخزين مؤقت لكل طلب ومُنظمة (تجنب N+1 في ContactResource)
		$key = $organization ? $organization->id : (session()->get('current_organization') ?? 'session');
		static $cache = [];
		if (!isset($cache[$key])) {
			$cache[$key] = self::currentUserIsAgent() && self::getTicketSettings($organization);
		}
		return $cache[$key];
	}
	private static function getTicketSettings(?Organization $organization = null){
        // Retrieve the settings for the current organization
        $settings = $organization?: Organization::where('id', session()->get('current_organization'))->first();

        if ($settings) {
            // Decode the JSON metadata column into an associative array
            $metadata = json_decode($settings->metadata, true);

            if (isset($metadata['tickets'])) {
                // If the 'contacts' key exists, retrieve the 'location' value
                $encryptContactsForAgents = $metadata['tickets']['encrypt_contacts_for_agents']??false;

                // Now, you have the location value available
                return $encryptContactsForAgents;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }
	
    public function toggleTicketStatus(string $status)
    {
		ChatTicket::where('contact_id', $this->id)->update([
            'status' => $status,
            'assigned_to' => auth()->user()->id
        ]);
        $statusDescription = $status == 'closed' ? 'opened to closed' : 'closed to open';

        $ticketId = ChatTicketLog::insertGetId([
            'contact_id' => $this->id,
            'description' => 'Conversation was moved from ' . $statusDescription,
            'created_at' =>  now()
        ]);

        ChatLog::insert([
            'contact_id' => $this->id,
            'entity_type' => 'ticket',
            'entity_id' => $ticketId,
            'created_at' =>  now()
        ]);
		
    }
	public function ticket():HasOne
	{
		return $this->hasOne(ChatTicket::class, 'contact_id', 'id');
	}
	
	public function assignToUserThroughTicket(User $user):void
	{
		ChatTicket::where('contact_id', $this->id)->update([
			'assigned_to' => $user->id
		]);
		$ticketId = ChatTicketLog::insertGetId([
			'contact_id' => $this->id,
			'description' => 'Conversation was assigned to ' . $user->first_name . ' ' . $user->last_name,
			'created_at' =>  now()
		]);

		ChatLog::insert([
			'contact_id' => $this->id,
			'entity_type' => 'ticket',
			'entity_id' => $ticketId,
			'created_at' =>  now()
		]);
		
	}
}
