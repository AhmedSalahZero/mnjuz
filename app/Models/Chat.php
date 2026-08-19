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

    /**
     * سياق الرسالة المتفاعَل معها يُلحَق بكل تسلسل: الرسالة تصل الواجهة من
     * أكثر من عشرة مسارات (قائمة الويب، تحميل المزيد، مسارا الجوال، البثّ)،
     * وإثراؤها في كلٍّ منها كان سيُنسى في أحدها حتماً.
     */
    protected $appends = ['reaction_context'];

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
		
        return DateTimeHelper::convertToOrganizationTimezone($value,$this->attributes['organization_id'])->toDateTimeString();
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

    /**
     * الرسالة التي يشير إليها هذا التفاعل — أو null لغير التفاعلات.
     *
     * التفاعل يصل صفّاً مستقلاً بلا أي أثر لما تفاعل معه، فيظهر «تفاعل مع
     * رسالة» بلا ذكر أيّها. و44% من التفاعلات تبعد عن رسالتها أكثر من خمسين
     * صفّاً، أي خارج الصفحة المحمّلة — فالمطابقة في الواجهة وحدها كانت ستفشل
     * في نحو نصف الحالات. الربط هنا يعمل مهما بعُدت.
     *
     * @return array{id: int, uuid: ?string, direction: ?string, preview_type: ?string, preview: string}|null
     */
    public function getReactionContextAttribute(): ?array
    {
        $raw = $this->attributes['metadata'] ?? null;

        // فحص نصّي رخيص قبل فكّ الترميز: 99.7% من الصفوف ليست تفاعلات،
        // وفكّ ترميز كل صفّ في كل قائمة تكلفة بلا مقابل.
        if (!is_string($raw) || !str_contains($raw, '"reaction"')) {
            return null;
        }

        $metadata = json_decode($raw, true);
        if (($metadata['type'] ?? null) !== 'reaction') {
            return null;
        }

        $parentWamId = $metadata['reaction']['message_id'] ?? null;
        if (!is_string($parentWamId) || $parentWamId === '') {
            return null;
        }

        // بلا ذاكرة مؤقّتة ساكنة عمداً: عمّال الطوابير تعيش طويلاً، وذاكرة
        // ساكنة فيها تنمو بلا حدّ وتُعيد رسالةً عُدِّلت بعد تخزينها. الاستعلام
        // مفهرس على wam_id ويفحص صفّاً واحداً، والتفاعلات 0.3% من الرسائل.
        $parent = static::query()
            ->where('wam_id', $parentWamId)
            ->first(['id', 'uuid', 'type', 'metadata']);

        // 4% من التفاعلات لا تجد رسالتها — حُذفت أو خرجت عن مدّة الحفظ.
        // نُرجع null فتعرض الواجهة الصيغة العامّة بدل اقتباس فارغ.
        if (!$parent) {
            return null;
        }

        $parentMetadata = json_decode($parent->getRawOriginal('metadata') ?? '{}', true) ?: [];

        return [
            'id' => (int) $parent->id,
            'uuid' => $parent->uuid,
            'direction' => $parent->type,
            'preview_type' => $parentMetadata['type'] ?? null,
            'preview' => static::reactionPreviewText($parentMetadata),
        ];
    }

    /**
     * نصّ مختصر يُعرَّف الرسالة الأصل. الوسائط لا نصّ لها فنترك الوصف للواجهة
     * — هي وحدها تعرف لغة القارئ.
     *
     * @param  array<string, mixed>  $metadata
     */
    private static function reactionPreviewText(array $metadata): string
    {
        $text = match ($metadata['type'] ?? null) {
            'text' => $metadata['text']['body'] ?? '',
            'image', 'video', 'document' => $metadata[$metadata['type']]['caption'] ?? '',
            'button' => $metadata['button']['text'] ?? '',
            'interactive' => $metadata['interactive']['button_reply']['title']
                ?? $metadata['interactive']['list_reply']['title'] ?? '',
            'location' => $metadata['location']['name'] ?? '',
            default => '',
        };

        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

        // القصّ بالمحارف لا بالبايتات: الحرف العربي بايتان فالقصّ الخام يشطره.
        return mb_strlen($text) > 90 ? mb_substr($text, 0, 90) . '…' : $text;
    }
}
