<?php

namespace Modules\FlowBuilder\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Flow extends Model {
    use HasFactory;
    use HasUuid;

    protected $guarded = [];
    public $timestamps = true;

    /**
     * مسارات المنشأة المختارة في الجلسة فقط. البحث بالـ uuid وحده كان يفتح
     * flow أيّ منشأة لمن عرف معرّفه.
     */
    public function scopeForCurrentOrganization($query)
    {
        return $query->where('organization_id', session()->get('current_organization'));
    }

    /**
     * abort(404) لا firstOrFail: معالج الأخطاء يحوّل ModelNotFoundException
     * في طلبات JSON إلى 500، والمحرّر يحفظ ويرفع عبر axios.
     */
    public static function ownedOrAbort(string $uuid): self
    {
        return static::forCurrentOrganization()->where('uuid', $uuid)->first() ?? abort(404);
    }

    // Define the relationship to FlowLog
    public function flowLogs()
    {
        return $this->hasMany(FlowLog::class, 'flow_id', 'id');
    }

    public function listAll($organizationId, $searchTerm)
    {
        return $this->where('organization_id', $organizationId)
                    ->where('deleted_at', null)
                    ->where(function ($query) use ($searchTerm) {
                        $query->where('name', 'like', '%' . $searchTerm . '%');
                    })
                    ->withCount('flowLogs')
                    ->latest()
                    ->paginate(10);
    }
}