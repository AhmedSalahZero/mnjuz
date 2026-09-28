<?php

namespace App\Models;

use App\Http\Traits\HasUuid;
use App\Services\Messaging\Channel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * حساب منصّة مربوط بمنشأة: صفحة فيسبوك، بوت تيليجرام، حساب إنستغرام،
 * حساب تيك توك للأعمال.
 *
 * لماذا جدول مستقلّ لا مفتاح جديد في organizations.metadata؟
 *   ١. المنشأة الواحدة قد تربط أكثر من صفحة على نفس المنصّة.
 *   ٢. توجيه الـ webhook يحتاج بحثاً بـ (channel, external_id) — وهو فهرس
 *      هنا، بينما البحث داخل JSON لكل منشأة مسحٌ كامل للجدول.
 *   ٣. صلاحيات تيك توك تنتهي كل يوم وتحتاج عمودَي تاريخ يُستعلَم عنهما
 *      بمهمّة مجدولة، وهذا لا يصلح داخل JSON.
 *
 * واتساب تبقى في organizations.metadata.whatsapp كما هي — لا نلمسها.
 */
class ChannelAccount extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $guarded = [];

    public $timestamps = true;

    /**
     * credentials مشفّرة في قاعدة البيانات: توكنات الصفحات ومفاتيح التطبيقات
     * تعطي سيطرة كاملة على حساب العميل، فلا تُخزَّن نصّاً صريحاً.
     */
    protected $casts = [
        'credentials' => 'encrypted:array',
        'settings' => 'array',
        'token_expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
    ];

    /**
     * credentials لا تخرج أبداً إلى الواجهة. ما تحتاجه الواجهة هو «هل ضُبط؟»
     * لا القيمة نفسها — على نفس نهج Organization::toArray مع access_token.
     */
    protected $hidden = ['credentials'];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REAUTHORIZATION_REQUIRED = 'reauthorization_required';
    public const STATUS_DISABLED = 'disabled';

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'id');
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class, 'channel_account_id', 'id');
    }

    /* ───────────────────────── نطاقات الاستعلام ───────────────────────── */

    public function scopeForChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** الحساب الذي يخصّه webhook وارد — الفهرس (channel, external_id). */
    public static function findForWebhook(string $channel, string $externalId): ?self
    {
        return static::query()
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->first();
    }

    /* ───────────────────────── الحالة ───────────────────────── */

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function needsReauthorization(): bool
    {
        return $this->status === self::STATUS_REAUTHORIZATION_REQUIRED;
    }

    public function markReauthorizationRequired(?string $reason = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_REAUTHORIZATION_REQUIRED,
            'last_error' => $reason,
        ])->save();
    }

    public function markActive(): void
    {
        $this->forceFill([
            'status' => self::STATUS_ACTIVE,
            'last_error' => null,
        ])->save();
    }

    public function recordError(string $message): void
    {
        $this->forceFill(['last_error' => mb_substr($message, 0, 1000)])->save();
    }

    /* ───────────────────────── بيانات الاعتماد ───────────────────────── */

    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials ?? [], $key, $default);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    /** دمج تحديث جزئي على credentials دون فقد بقيّة المفاتيح. */
    public function mergeCredentials(array $values): void
    {
        $this->credentials = array_merge($this->credentials ?? [], $values);
        $this->save();
    }

    /**
     * هل انتهت صلاحية التوكن، أو ستنتهي خلال الهامش المُعطى؟
     *
     * الهامش معامل لا ثابت: مسار الإرسال يريد «هل انتهى فعلاً؟» (دقائق)،
     * والمهمّة المجدولة تريد «هل يوشك؟» (ساعات) لتُجدّد قبل أي انقطاع.
     */
    public function tokenExpired(int $leewayMinutes = 5): bool
    {
        return $this->token_expires_at !== null
            && $this->token_expires_at->subMinutes($leewayMinutes)->isPast();
    }

    public function refreshTokenExpired(): bool
    {
        return $this->refresh_token_expires_at !== null
            && $this->refresh_token_expires_at->isPast();
    }

    /* ───────────────────────── العرض ───────────────────────── */

    public function getChannelLabelAttribute(): string
    {
        return Channel::label($this->channel);
    }

    /**
     * ما يُعرَض في صفحة الإعدادات: كل شيء عدا الأسرار، مع علامة تقول
     * إن كانت مضبوطة.
     */
    public function toSettingsArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'channel' => $this->channel,
            'channel_label' => Channel::label($this->channel),
            'external_id' => $this->external_id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar' => $this->avatar,
            'status' => $this->status,
            'is_active' => $this->isActive(),
            'needs_reauthorization' => $this->needsReauthorization(),
            'last_error' => $this->last_error,
            'token_expires_at' => $this->token_expires_at?->toIso8601String(),
            'settings' => $this->settings ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
