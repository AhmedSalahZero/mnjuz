<?php

namespace App\Models;

use App\Http\Traits\HasUuid;
use App\Services\Messaging\Channel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * قالب محفوظ لقناة بلا موافقة: رسالة يكتبها التاجر مرّة ويختارها في
 * حملاته. جاهز فور حفظه — لا مراجعة ولا حالة.
 *
 * الحملة تنسخ محتواه لحظة إنشائها (message_payload)، فتعديله أو حذفه لا
 * يغيّر حملة مجدولة.
 */
class ChannelTemplate extends Model
{
    use HasUuid;
    use SoftDeletes;

    /** القنوات التي لها قوالب محفوظة الآن. SMS تُضاف حين تُبنى. */
    public const CHANNELS = [Channel::TELEGRAM, Channel::EMAIL];

    protected $guarded = [];

    protected $casts = [
        'media' => 'array',
        // تصميم الـ builder لقالب البريد (EmailDesign)، null لتيليجرام والقديم.
        'design' => 'array',
    ];

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /** ما يُنسخ إلى الحملة. */
    public function campaignPayload(): array
    {
        $payload = [
            'text' => (string) $this->body,
            'media' => is_array($this->media) && !empty($this->media['url']) ? $this->media : null,
            'subject' => $this->subject,
        ];

        if (is_array($this->design) && !empty($this->design['blocks'])) {
            $payload['design'] = $this->design;
        }

        return $payload;
    }
}
