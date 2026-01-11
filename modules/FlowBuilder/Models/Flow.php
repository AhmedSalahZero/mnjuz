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