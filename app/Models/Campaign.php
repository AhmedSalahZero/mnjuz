<?php

namespace App\Models;

use App\Helpers\DateTimeHelper;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model {
    use HasFactory;
    use HasUuid;

    protected $guarded = [];
    public $timestamps = false;

    /** كما في Contact و Chat: القيمة الافتراضية تظهر على الكائن فوراً. */
    protected $attributes = [
        'channel' => \App\Services\Messaging\Channel::DEFAULT,
    ];

    /**
     * حملة قالب (واتساب) أم حملة رسالة حرّة (بقيّة القنوات)؟
     *
     * نسأل عن القناة لا عن وجود template_id: الحملة القديمة قد تحمل قالباً
     * محذوفاً، والسؤال عن القناة يُجيب دائماً.
     */
    public function isTemplateCampaign(): bool
    {
        return ($this->channel ?? \App\Services\Messaging\Channel::DEFAULT)
            === \App\Services\Messaging\Channel::WHATSAPP;
    }

    /**
     * الرسالة الحرّة: {"text": "...", "media": {"url", "type", "name"}|null}
     *
     * @return array{text: string, media: ?array}
     */
    public function messagePayload(): array
    {
        $decoded = $this->message_payload ? json_decode($this->message_payload, true) : null;

        if (!is_array($decoded)) {
            return ['text' => '', 'media' => null];
        }

        return [
            'text' => (string) ($decoded['text'] ?? ''),
            'media' => is_array($decoded['media'] ?? null) ? $decoded['media'] : null,
        ];
    }

    /**
     * قيمة من حمولة الرسالة غير النصّ والمرفق: موضوع البريد، القالب المحفوظ
     * الذي أُنشئت منه ({uuid, name})، لغة التذييل. messagePayload() تبقى
     * بشكلها كما هي — مُرسِلات القنوات تعتمد عليه.
     */
    public function payloadValue(string $key, mixed $default = null): mixed
    {
        $decoded = $this->message_payload ? json_decode($this->message_payload, true) : null;

        return is_array($decoded) && array_key_exists($key, $decoded) ? $decoded[$key] : $default;
    }

    public function channelAccount()
    {
        return $this->belongsTo(ChannelAccount::class, 'channel_account_id', 'id');
    }

    public function getCreatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getDeletedAtAttribute($value)
    {
        if ($value === null) {
            return null;
        }

        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getScheduledAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function organization(): BelongsTo {
        return $this->belongsTo(Organization::class, 'organization_id', 'id');
    }

    public function template(): BelongsTo {
        return $this->belongsTo(Template::class, 'template_id', 'id');
    }

    public function contactGroup(): BelongsTo {
        return $this->belongsTo(ContactGroup::class, 'contact_group_id', 'id');
    }

    public function campaignLogs(): HasMany {
        return $this->hasMany(CampaignLog::class, 'campaign_id', 'id');
    }

    public function contactsCount(){
        return $this->campaignLogs->count();
    }

    public function contactGroupCount(){
        return $this->contactGroup ? $this->contactGroup->contacts->count() : 0;
    }

    public function sentCount(){
        return $this->campaignLogs()
            ->where('status', 'success')
            ->whereHas('chat', function ($query) {
                $query->whereIn('status', ['accepted', 'sent', 'delivered', 'read']);
            })
            ->count();
    }

    public function deliveryCount(){
        return $this->campaignLogs()
            ->where('status', 'success')
            ->whereHas('chat', function ($query) {
                $query->whereIn('status', ['delivered', 'read']);
            })
            ->count();
    }

    public function failedCount(){
        $failedToSendCount = $this->campaignLogs()->where('status', 'failed')->count();

        $chatFailedCount = $this->campaignLogs()
            ->where('status', 'success')
            ->whereHas('chat', function ($query) {
                $query->where('status', 'failed');
            })
            ->count();

        return $failedToSendCount + $chatFailedCount;
    }

    public function readCount(){
        return $this->campaignLogs()
            ->where('status', 'success')
            ->whereHas('chat', function ($query) {
                $query->where('status', 'read');
            })
            ->count();
    }

    public function getCounts(){
        // البريد بلا صفوف chats: النتيجة على السجلّ نفسه، والتسليم والقراءة
        // لا يُتتبّعان (صفر). الأعمدة نفسها ليقرأها كل عارضٍ كما هو.
        if (($this->channel ?? \App\Services\Messaging\Channel::DEFAULT) === \App\Services\Messaging\Channel::EMAIL) {
            return $this->campaignLogs()
                ->selectRaw('
                    COUNT(*) as total_message_count,
                    SUM(CASE WHEN campaign_logs.status = "success" THEN 1 ELSE 0 END) as total_sent_count,
                    0 as total_delivered_count,
                    SUM(CASE WHEN campaign_logs.status = "failed" THEN 1 ELSE 0 END) as total_failed_count,
                    0 as total_read_count
                ')
                ->where('campaign_logs.campaign_id', $this->id)
                ->first();
        }

        return $this->campaignLogs()
            ->selectRaw('
                COUNT(*) as total_message_count,
                SUM(CASE WHEN campaign_logs.status = "success" AND chat.status IN ("accepted", "sent", "delivered", "read") THEN 1 ELSE 0 END) as total_sent_count,
                SUM(CASE WHEN campaign_logs.status = "success" AND chat.status IN ("delivered", "read") THEN 1 ELSE 0 END) as total_delivered_count,
                SUM(CASE WHEN campaign_logs.status = "failed" THEN 1 ELSE 0 END) + 
                    SUM(CASE WHEN campaign_logs.status = "success" AND chat.status = "failed" THEN 1 ELSE 0 END) as total_failed_count,
                SUM(CASE WHEN campaign_logs.status = "success" AND chat.status = "read" THEN 1 ELSE 0 END) as total_read_count
            ')
            ->leftJoin('chats as chat', 'chat.id', '=', 'campaign_logs.chat_id')
            ->where('campaign_logs.campaign_id', $this->id)
            ->first();
    }
}
