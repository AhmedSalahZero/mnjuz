<?php

namespace App\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * استيراد سجلّ محادثات من ملف CSV.
 *
 * المراحل: pending_validation → validating → (invalid | queued) → importing
 * → (completed | failed | cancelled)، ومن الثلاث الأخيرة يمكن التراجع:
 * rolling_back → rolled_back.
 */
class ChatImport extends Model
{
    use HasUuid;

    public const PENDING_VALIDATION = 'pending_validation';
    public const VALIDATING = 'validating';
    public const INVALID = 'invalid';
    public const QUEUED = 'queued';
    public const IMPORTING = 'importing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const CANCELLING = 'cancelling';
    public const CANCELLED = 'cancelled';
    public const ROLLING_BACK = 'rolling_back';
    public const ROLLED_BACK = 'rolled_back';

    /** حالات يعمل فيها شيء على الطابور — لا يبدأ استيراد ثانٍ للمنشأة أثناءها. */
    public const ACTIVE_STATUSES = [
        self::PENDING_VALIDATION,
        self::VALIDATING,
        self::QUEUED,
        self::IMPORTING,
        self::CANCELLING,
        self::ROLLING_BACK,
    ];

    /** حالات انتهت كتابتها ويمكن التراجع عنها. */
    public const ROLLBACKABLE_STATUSES = [self::COMPLETED, self::FAILED, self::CANCELLED];

    /** حالات يمكن استئناف الكتابة منها. */
    public const RESUMABLE_STATUSES = [self::FAILED, self::CANCELLED];

    protected $guarded = [];

    protected $hidden = ['id', 'file_path', 'errors_file_path', 'organization_id'];

    protected $casts = [
        'errors' => 'array',
        'ignored_columns' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'file_size' => 'integer',
        'progress_bytes' => 'integer',
        'cursor_offset' => 'integer',
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'new_messages_estimate' => 'integer',
        'new_contacts_estimate' => 'integer',
        'imported_messages' => 'integer',
        'duplicate_rows' => 'integer',
        'contacts_created' => 'integer',
        'contacts_matched' => 'integer',
        'rolled_back_messages' => 'integer',
        'error_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function canCancel(): bool
    {
        return in_array($this->status, [self::PENDING_VALIDATION, self::VALIDATING, self::QUEUED, self::IMPORTING], true);
    }

    public function canResume(): bool
    {
        // الاستئناف للكتابة وحدها: استيراد لم يجتز الفحص لا موضع له يُستأنف منه،
        // واستيراد بدأ التراجع عنه لا يُستأنف — يُكمَل التراجع.
        return in_array($this->status, self::RESUMABLE_STATUSES, true)
            && $this->started_at !== null
            && $this->processed_rows < $this->total_rows
            && $this->rolled_back_messages === 0
            && $this->file_path !== null;
    }

    public function canRollback(): bool
    {
        return in_array($this->status, self::ROLLBACKABLE_STATUSES, true) && $this->imported_messages > $this->rolled_back_messages;
    }

    /**
     * لا تقدّم منذ مدّة: لا عامل يستمع لطابور الاستيراد، أو سقط العامل في
     * منتصفه. كل دفعة وكل ألفي صفّ فحص تُحدّث updated_at، فسكوته دليل.
     * المنتظِر في الطابور يُنذَر بعد خمس دقائق، والعامل بعد خمس عشرة —
     * أطول من retry_after (11 دقيقة) الذي يعيد بعده الطابور المحاولة.
     */
    public function isStalled(): bool
    {
        if ($this->updated_at === null) {
            return false;
        }

        $minutes = match ($this->status) {
            self::PENDING_VALIDATION, self::QUEUED => 5,
            self::VALIDATING, self::IMPORTING, self::CANCELLING, self::ROLLING_BACK => 15,
            default => null,
        };

        return $minutes !== null && $this->updated_at->lt(now()->subMinutes($minutes));
    }

    /** في الاستيراد المقبول كل خطأ مسجَّل صفّ تُخطّي لرقم خاطئ؛ والمرفوض لم يُتخطَّ منه شيء. */
    public function skippedRows(): int
    {
        return $this->status === self::INVALID ? 0 : (int) $this->error_count;
    }

    /** المرحلة التي يعرضها شريط التقدّم. */
    public function phase(): string
    {
        return match ($this->status) {
            self::PENDING_VALIDATION, self::VALIDATING => 'validating',
            self::QUEUED, self::IMPORTING, self::CANCELLING => 'importing',
            self::ROLLING_BACK => 'rolling_back',
            default => 'done',
        };
    }

    /** نسبة التقدّم في المرحلة الحاليّة (0–100). */
    public function progressPercent(): int
    {
        $ratio = match ($this->status) {
            self::PENDING_VALIDATION => 0,
            self::VALIDATING => $this->file_size > 0 ? $this->progress_bytes / $this->file_size : 0,
            self::QUEUED => 0,
            self::IMPORTING, self::CANCELLING => $this->total_rows > 0 ? $this->processed_rows / $this->total_rows : 0,
            self::ROLLING_BACK => $this->imported_messages > 0 ? $this->rolled_back_messages / $this->imported_messages : 0,
            default => 1,
        };

        return (int) max(0, min(100, floor($ratio * 100)));
    }

    /** ما يقرؤه شريط التقدّم وجدول السجلّ. */
    public function toStatusPayload(bool $withErrors = true): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'phase' => $this->phase(),
            'percent' => $this->progressPercent(),
            'original_name' => $this->original_name,
            'file_size' => $this->file_size,
            'default_country' => $this->default_country,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'new_messages_estimate' => $this->new_messages_estimate,
            'new_contacts_estimate' => $this->new_contacts_estimate,
            'imported_messages' => $this->imported_messages,
            'duplicate_rows' => $this->duplicate_rows,
            'contacts_created' => $this->contacts_created,
            'contacts_matched' => $this->contacts_matched,
            'rolled_back_messages' => $this->rolled_back_messages,
            'error_count' => $this->error_count,
            'skipped_rows' => $this->skippedRows(),
            'errors' => $withErrors ? ($this->errors ?? []) : [],
            'has_errors_file' => $this->errors_file_path !== null,
            'error_message' => $this->error_message,
            'ignored_columns' => $this->ignored_columns ?? [],
            'can_cancel' => $this->canCancel(),
            'can_resume' => $this->canResume(),
            'can_rollback' => $this->canRollback(),
            'stalled' => $this->isStalled(),
            'user_name' => $this->relationLoaded('user') && $this->user
                ? trim(($this->user->first_name ?? '') . ' ' . ($this->user->last_name ?? ''))
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
