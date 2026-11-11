<?php

namespace App\Models;
use App\Events\ContactAiAssistanceUpdated;
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

    /**
     * القناة الافتراضية في الذاكرة كذلك لا في المخطّط وحده.
     *
     * القيمة الافتراضية في MySQL تُطبَّق عند الكتابة، فالكائن العائد من
     * Contact::create بلا ذكر للقناة كان يحمل channel = null حتى يُعاد
     * تحميله. وكل من يقرأ ->channel مباشرةً بعد الإنشاء — كـ
     * ChannelManager::forContact — كان يرى null بدل 'whatsapp'.
     * والنتيجة هنا ليست قيمةً ناقصة بل توجيه رسالة إلى سائق خاطئ.
     */
    protected $attributes = [
        'channel' => \App\Services\Messaging\Channel::DEFAULT,
    ];

    /**
     * يضبط مساعد الذكاء الاصطناعي لهذه المحادثة، ويُعلم الشاشات المفتوحة إن تغيّرت
     * القيمة فعلاً.
     *
     * نقطة واحدة لكل من يغيّر الحالة — المفتاح في الترويسة، وكلمات البدء والإيقاف،
     * والتفعيل التلقائي — كي لا يُنسى البثّ في أحدها فتبقى الشاشة المفتوحة على
     * حالة قديمة. ولا شيء يُكتب ولا يُبثّ إن لم تتغيّر القيمة.
     *
     * @return bool هل تغيّرت القيمة
     */
    public function setAiAssistanceEnabled(bool $enabled): bool
    {
        if ((bool) $this->ai_assistance_enabled === $enabled) {
            return false;
        }

        $this->forceFill(['ai_assistance_enabled' => $enabled])->save();

        event(new ContactAiAssistanceUpdated(
            (int) $this->organization_id,
            (int) $this->id,
            (string) $this->uuid,
            $enabled
        ));

        return true;
    }

    public function getCreatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getUpdatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    /**
     * توحيد نص البحث قبل استخدامه في أي واجهة (ويب أو موبايل):
     * حذف محارف التحكم بالاتجاه التي تلتصق بالنص عند النسخ في واجهة عربية،
     * وتحويل الأرقام العربية/الفارسية إلى لاتينية، وقصّ المسافات الطرفية.
     */
    public static function normalizeSearchTerm(?string $term): string
    {
        if ($term === null) {
            return '';
        }

        $stripped = preg_replace(
            '/[\x{200B}-\x{200F}\x{061C}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{00A0}]/u',
            '',
            $term
        );
        $term = $stripped ?? $term;

        return trim(str_replace(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $term
        ));
    }

    /**
     * بحث موحّد لجهات الاتصال يستخدمه الويب والموبايل معاً.
     * نميّز بين البحث برقم الهاتف والبحث بالاسم/البريد لتفادي النتائج غير الدقيقة:
     * - إذا كان النص أرقاماً (مع رموز الهاتف + ( ) - ومسافات فقط) نبحث في phone
     *   و formatted_phone بعد تجريد الرموز من الطرفين، فلا تظهر أسماء لا علاقة لها.
     * - غير ذلك نبحث في الاسم والبريد فقط.
     */
    public function scopeSearchTerm(Builder $query, ?string $searchTerm): Builder
    {
        $searchTerm = self::normalizeSearchTerm($searchTerm);
        if ($searchTerm === '') {
            return $query;
        }

        $digits = preg_replace('/\D+/', '', $searchTerm);
        $isPhoneSearch = $digits !== '' && preg_replace('/[\s()+\-]/', '', $searchTerm) === $digits;

        return $query->where(function ($q) use ($searchTerm, $digits, $isPhoneSearch) {
            if ($isPhoneSearch) {
                $stripPhone = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(%s, '+', ''), ' ', ''), '-', ''), '(', ''), ')', '')";
                foreach (self::phoneSearchNeedles($digits) as $needle) {
                    $like = "%{$needle}%";
                    $q->orWhereRaw(sprintf($stripPhone, 'contacts.phone') . ' LIKE ?', [$like])
                      ->orWhereRaw(sprintf($stripPhone, "COALESCE(contacts.formatted_phone, '')") . ' LIKE ?', [$like]);
                }

                return;
            }

            $q->where('contacts.first_name', 'like', "%{$searchTerm}%")
              ->orWhere('contacts.last_name', 'like', "%{$searchTerm}%")
              ->orWhere('contacts.email', 'like', "%{$searchTerm}%")
              ->orWhereRaw(
                  "CONCAT(contacts.first_name, ' ', contacts.last_name) LIKE ?",
                  ["%{$searchTerm}%"]
              );
        });
    }

    /**
     * صيغ الرقم التي نجرّبها: الرقم كما أُدخل، وبدونه الأصفار البادئة — لأن
     * المستخدم قد يكتب 0535959959 أو 00966535959959 بينما المخزَّن +966535959959.
     *
     * @return array<int, string>
     */
    private static function phoneSearchNeedles(string $digits): array
    {
        $needles = [$digits];

        $withoutLeadingZeros = ltrim($digits, '0');
        if ($withoutLeadingZeros !== $digits && strlen($withoutLeadingZeros) >= 5) {
            $needles[] = $withoutLeadingZeros;
        }

        return $needles;
    }

    public function getAllContacts($organizationId, $searchTerm)
    {
        return $this->with('contactGroups')
            ->where('organization_id', $organizationId)
            ->where('deleted_at', null)
            ->searchTerm($searchTerm)
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

    /** زائر المحادثة المباشرة الذي تمثّله الجهة (null لغيرها). */
    public function webchatVisitor()
    {
        return $this->hasOne(WebChatVisitor::class, 'contact_id');
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
    /**
     * آخر رسالة حقيقية في المحادثة — تُعرض معاينةً في قائمة المحادثات.
     *
     * التفاعل بالإيموجي مستثنى: واتساب ترسله رسالةً مستقلّة، فكان يصير «آخر
     * رسالة» في 2,022 محادثة ويُظهر سطر المعاينة فارغاً — لا فرع يعرضه ولا
     * نصّ فيه. تخطّيه يُعيد للسطر ما يُذكّر الموظّف بما دار فعلاً.
     *
     * الترتيب في القائمة يبقى على latest_chat_created_at، فالتفاعل ما زال
     * يرفع المحادثة إلى الأعلى — وهو صحيح: نشاط جديد وقع بالفعل.
     */
    public function lastChat()
    {
        return $this->hasOne(Chat::class, 'contact_id')
            ->ofMany(['created_at' => 'max'], function (Builder $q) {
                $q->whereNull('deleted_at')
                    ->whereHas('chatLog')
                    ->where('metadata', 'not like', '%"type":"reaction"%');
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

    /**
     * فهارس قائمة المحادثات: (المنشأة، الحذف، آخر رسالة)، وبالقناة لفلترها.
     * فتبدأ القائمة دائماً من جهات المنشأة نفسها بترتيبها، وتقف عند الصفحة.
     *
     * بلا تقييد يختار المُحسِّن تقاطع فهرسي organization_id وdeleted_at (يقرأ
     * 631 ألف صفّ «غير محذوف» من كل المنشآت)، أو يبدأ من chat_tickets بحالتها
     * — وchat_tickets بلا organization_id: فلتر «مفتوحة» يمرّ على 442 ألف تذكرة
     * مفتوحة في المنصّة كلها. ولذلك لا PRIMARY هنا: يسمح بذلك البدء.
     *
     * أسوأ حالة صارت محدودة بحجم المنشأة لا المنصّة: فلترٌ يطابق قليلاً (مغلقة
     * في منشأة تذاكرها مفتوحة) يمرّ على جهاتها كلها — عشرات إلى مئات الأجزاء
     * من الثانية. قيس على الإنتاج (قراءة فقط): التمرير في منشأة 54 من 444ms إلى
     * 7ms، الصفحة الأولى لمنشأة صغيرة من 281ms إلى 0.9ms، «مفتوحة» من 430ms إلى
     * 1ms، «غير مقروءة» من 3.1s إلى 0.4s، والبحث برقم من 2.1s إلى 0.1s.
     */
    public const CHAT_LIST_INDEXES = 'idx_contacts_org_deleted_latest_chat, idx_contacts_org_channel_latest_chat';

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
        // فلتر القناة. يُضاف آخراً ليبقى كل مُستدعٍ قائم صحيحاً بلا تعديل.
        $channel = null,
    ) {
        $query = $this->newQuery()
            // «مغلقة» تطابق قليلاً في أغلب المنشآت: البدء من جهات المنشأة يمرّ
            // عليها كلّها، والمُحسِّن وحده يبدأ من التذاكر المغلقة أسرع. تبقى كما
            // هي اليوم.
            ->when(!($ticketingActive && $ticketState === 'closed'), fn ($q) => $q->forceIndex(self::CHAT_LIST_INDEXES))
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
                'contacts.channel',
                // رقم العرض لقنوات بلا رقم هويّة (المحادثة المباشرة).
                'contacts.formatted_phone',
            ])
			// ->where('contacts.id', 142069)
            ->where('contacts.organization_id', $organizationId)
            ->whereNotNull('contacts.latest_chat_created_at')
            ->whereNull('contacts.deleted_at');

        // الفهرس idx_contacts_org_channel_latest_chat يخدم هذا الشرط مع
        // الترتيب على latest_chat_created_at في جملة واحدة.
        if ($channel !== null && $channel !== '') {
            $query->where('contacts.channel', $channel);
        }

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
    
        // آخر رسالة لكل صفّ تُحسب بعد الترقيم لصفوف الصفحة وحدها (attachLastChats)،
        // لا هنا: subquery في الـ select يُحسب قبل الترتيب لكل صفّ حين يرتّب
        // MySQL نتيجة ربطٍ (Stream results ثم Sort) — 42 ألف مرّة لفلتر «مفتوحة»
        // في منشأة كبيرة: 31 ثانية بدل أجزاء من الثانية.

        // last_inbound_chat نعتمد على العمود last_inbound_chat_created_at
        $with = [];
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

        // هل يُغيّر ربط التذاكر عدد الصفوف؟ لا حين يكون LEFT JOIN بلا شرط عليه:
        // لكل جهة اتصال تذكرة «أحدث» واحدة على الأكثر (القيد الفريد
        // uq_chat_tickets_single_latest)، فالعدّ بدونه هو نفسه.
        $ticketJoinKeepsRowCount = false;

        // ✅ شروط التذاكر — INNER JOIN عند الفلترة لبدء من chat_tickets المفهرسة
        if ($ticketingActive) {
            $isTicketFiltered = in_array($ticketState, ['open', 'closed', 'unassigned'], true);
            $ticketJoinKeepsRowCount = !$isTicketFiltered && !($role === 'agent' && !$allowAgentsViewAllChats);
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
                'chat_tickets.assigned_seen as ticket_assigned_seen',
            ]);

            // صلاحيات الوكلاء
            if ($role === 'agent' && !$allowAgentsViewAllChats) {
                $query->where('chat_tickets.assigned_to', auth()->id());
            }
        }

        // ✅ البحث - محسّن (نفس المنطق المستخدم في الموبايل عبر scopeSearchTerm)
        $query->searchTerm($searchTerm);

        // ✅ الترتيب باستخدام العمود الموجود
        $query->orderBy('contacts.latest_chat_created_at', $sortDirection)
		->orderBy('contacts.id', 'desc');
		/**
		 * @var Builder $query 
		 */
		if ($perPage !== null) {
			// العدّ يُعاد مع كل تحميل للقائمة وكل تمرير. بربط التذاكر يمرّ على
			// كل جهات المنشأة ويطابقها بتذاكرها: 185ms على منشأة 54 في الإنتاج،
			// و15ms بدونه — والعدد واحد (انظر $ticketJoinKeepsRowCount).
			$paginated = $ticketJoinKeepsRowCount
				? $query->paginate($perPage, ['*'], 'contact_page', $page, fn () => $query->toBase()
					->cloneWithout(['joins', 'indexHint'])
					->cloneWithoutBindings(['join'])
					->getCountForPagination())
				: $query->paginate($perPage, ['*'], 'contact_page', $page);
			self::attachLastChats($paginated->getCollection());

			return $paginated;
		}
		$rows = $query->get();
		self::attachLastChats($rows);

		return $rows;
		// return $query->paginate($perPage); // for web app pagination

    }

    /**
     * يملأ علاقة lastChat لصفوف الصفحة: استعلامٌ لمعرّفات آخر رسائلها
     * (lastChatIds)، ثم الرسائل مع وسائطها، وnull لمن لا رسالة له — كما كان
     * التحميل المسبق لـ lastChat() يفعل، فالموارد والواجهة لا ترى فرقاً.
     */
    public static function attachLastChats(\Illuminate\Support\Collection $contacts): void
    {
        $lastChatIds = self::lastChatIds($contacts->pluck('id')->filter()->all());
        $ids = array_values(array_unique(array_filter($lastChatIds)));
        $chats = $ids === []
            ? collect()
            : Chat::with('media')->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($contacts as $contact) {
            $contact->setRelation('lastChat', $chats->get($lastChatIds[$contact->id] ?? null));
        }
    }

    /**
     * معرّف آخر رسالة لكل جهة — نفس ما يُرجعه lastChat() بالضبط (الأحدث،
     * والأكبر معرّفاً عند التساوي، لا محذوفة، لها سجلّ، ليست تفاعلاً).
     *
     * lastChat() بـ ofMany يبني MAX(created_at) ثم MAX(id) على كل رسائل
     * الصفوف: شرطا chat_logs و«ليست تفاعلاً» يمنعان أي فهرس من الإجابة،
     * فيقرأ تاريخ الخمسين محادثة كلّه. هنا يمشي الفهرس من الأحدث لكل جهة
     * ويقف عند أول رسالة تحقّق الشروط — لصفوف الصفحة وحدها.
     *
     * FORCE INDEX: المُحسِّن يختار (contact_id, organization_id, deleted_at)
     * ثم يرتّب كل الرسائل. NO_SEMIJOIN: بدونه يحوّل EXISTS إلى semi-join
     * فيضيع التوقّف عند أول صفّ.
     *
     * @param array<int, int> $contactIds
     * @return array<int, ?int> contact_id => chat_id
     */
    public static function lastChatIds(array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }

        return DB::table('contacts')
            ->whereIn('contacts.id', $contactIds)
            ->select('contacts.id')
            ->selectRaw(
                '(SELECT /*+ NO_SEMIJOIN(@last_chat_log) */ last_chat.id'
                . ' FROM chats AS last_chat FORCE INDEX (idx_chats_contact_org_deleted_created)'
                . ' WHERE last_chat.contact_id = contacts.id'
                . ' AND last_chat.organization_id = contacts.organization_id'
                . ' AND last_chat.deleted_at IS NULL'
                . ' AND last_chat.metadata NOT LIKE ?'
                . ' AND EXISTS (SELECT /*+ QB_NAME(last_chat_log) */ 1 FROM chat_logs WHERE chat_logs.entity_id = last_chat.id AND chat_logs.entity_type = ?)'
                . ' ORDER BY last_chat.created_at DESC, last_chat.id DESC LIMIT 1) AS last_chat_id',
                ['%"type":"reaction"%', 'chat']
            )
            ->pluck('last_chat_id', 'id')
            ->map(fn ($id) => $id === null ? null : (int) $id)
            ->all();
    }

    

    public function getFirstNameAttribute()
    {
        // ?? null لا زينة: النموذج قد يُحمَّل بأعمدة منتقاة أو يُنشأ بلا اسم
        // (findOrCreateByPhone يُسقط الفارغ)، فقراءة المفتاح مباشرةً ترمي
        // «Undefined array key» وتُسقط الطلب كلّه — وقد أسقطت /api/send/template فعلاً.
        $firstName = $this->attributes['first_name'] ?? null;
        $firstName = $this->decodeUnicodeBytes($firstName);

        return $firstName;
    }

    public function getLastNameAttribute()
    {
        $lastName = $this->attributes['last_name'] ?? null;
        $lastName = $this->decodeUnicodeBytes($lastName);

        return $lastName;
    }

    public function getFullNameAttribute()
    {
        $firstName = $this->attributes['first_name'] ?? null;
        $lastName = $this->attributes['last_name'] ?? null;

        // Convert byte sequences to Unicode characters
        $firstName = $this->decodeUnicodeBytes($firstName);
        $lastName = $this->decodeUnicodeBytes($lastName);

        // الاسمان فارغان ⇒ مسافة واحدة، وهي «اسم» يمرّ كل فحوص الفراغ: تظهر
        // المحادثة بلا عنوان ويظنّ العارض أن للعميل اسماً. التشذيب يجعل
        // الغياب غياباً صريحاً فيصحّ الارتداد إلى الرقم.
        return trim($firstName . ' ' . $lastName);
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
        // نُرجع '' لا null عن عمد.
        //
        // قبل الحراسة كانت preg_replace_callback تتلقّى null فتُرجع '' مع تحذير
        // إهمال — فكان الـ API يُخرج first_name = "" لجهات الاتصال بلا اسم.
        // وحين أرجعتُ null «تنظيفاً» تغيّر عقد الـ API صامتاً، فرفض التطبيق
        // تحويل null إلى نصّ وأظهر خطأه العام عند البحث. المخرَج يبقى كما كان،
        // والحراسة تمنع الانهيار فقط.
        if ($value === null) {
            return '';
        }

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
		ChatTicket::where('contact_id', $this->id)
            ->where('is_latest', true)
            ->update([
                'status' => $status,
                'assigned_to' => auth()->user()->id,
                // الموظف الذي غيّر الحالة قد شاهد المحادثة بالفعل.
                'assigned_seen' => true,
            ]);

        // عند الإغلاق نعلّم الرسائل الواردة كمقروءة حتى لا تظهر محادثات قديمة
        // كغير مقروءة عند إعادة فتحها لاحقاً (تظهر فقط الرسالة الجديدة).
        if ($status === 'closed') {
            \App\Services\Chat\ChatReadService::markInboundAsRead((int) $this->id, (int) $this->organization_id);
        }
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

        // استبيان الرضا: نقطة واحدة تغطّي الإغلاق من الويب ومن التطبيق وأي
        // إغلاق آلي يُضاف لاحقاً. الخدمة لا ترمي أبداً فلا تُعطّل الإغلاق.
        if ($status === 'closed') {
            \App\Services\ConversationRatingService::onConversationClosed($this, auth()->id());
        }
		
    }
	public function ticket():HasOne
	{
		return $this->hasOne(ChatTicket::class, 'contact_id', 'id');
	}
	
	public function assignToUserThroughTicket(User $user):void
	{
		ChatTicket::where('contact_id', $this->id)
			->where('is_latest', true)
			->update([
				'assigned_to' => $user->id,
				// إسناد جديد لهذا الموظف: يظهر كمحادثة جديدة حتى يفتحها.
				'assigned_seen' => false,
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
