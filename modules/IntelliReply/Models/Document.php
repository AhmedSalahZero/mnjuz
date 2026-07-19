<?php

namespace Modules\IntelliReply\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Document extends Model {
    use HasFactory;
    use HasUuid;

    protected $guarded = [];
    public $timestamps = true;

    public function listAll($organizationId, $searchTerm)
    {
        return $this->where('organization_id', $organizationId)
                    ->where(function ($query) use ($searchTerm) {
                        $query->where('title', 'like', '%' . $searchTerm . '%');
                    })
                    ->latest()
                    ->paginate(10);
    }
}
