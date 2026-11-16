<?php

namespace App\Models;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Team extends Model {
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $guarded = [];
    public $timestamps = true;

    public function user():BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function organization():BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'id');
    }

    public function tickets():HasMany
    {
        return $this->hasMany(ChatTicket::class, 'assigned_to', 'user_id');
    }

    /**
     * أقلّ عضو نشط انشغالاً: أقلّ تذاكر مفتوحة مسندة إليه، والتعادل بترتيب
     * الانضمام. تعريف واحد للانشغال يستعمله الإسناد التلقائي وخطوة «تحويل إلى
     * موظف» في الأتمتة — فلا يختلفان في من هو «الأقلّ انشغالاً».
     *
     * @param  list<int>|null  $onlyUserIds  null = كل الأعضاء النشطين.
     */
    public static function leastBusyUserId(int $organizationId, ?array $onlyUserIds = null): ?int
    {
        $member = self::where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->when($onlyUserIds !== null, fn ($query) => $query->whereIn('user_id', $onlyUserIds))
            ->withCount(['tickets' => function ($query) {
                $query->where('status', 'open')
                    ->where('is_latest', true);
            }])
            ->orderBy('tickets_count', 'asc')
            ->orderBy('id', 'asc')
            ->first();

        return $member?->user_id !== null ? (int) $member->user_id : null;
    }
}
