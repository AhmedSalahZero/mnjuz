<?php

namespace App\Models;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutoReply extends Model {
    use HasFactory;
    use HasUuid;

    protected $guarded = [];
    public $timestamps = false;

    public function listAll($organizationId, $searchTerm)
    {
        return $this->where('organization_id', $organizationId)
                    ->where('deleted_at', null)
                    ->where(function ($query) use ($searchTerm) {
                        $query->where('name', 'like', '%' . $searchTerm . '%')
                              ->orwhere('trigger', 'like', '%' . $searchTerm . '%');
                    })
                    ->latest()
                    ->paginate(10);
    }
}
