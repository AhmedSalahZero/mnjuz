<?php

namespace App\Models;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillingDebit extends Model {
    use HasFactory;
    use HasUuid;

    protected $guarded = [];
    public $timestamps = false;

    public function listAll($searchTerm, $organizationId = null)
    {
        return $this->with(['organization'])
                    ->when($organizationId !== null, function ($query) use ($organizationId) {
                        return $query->where('organization_id', $organizationId);
                    })
                    ->latest()
                    ->paginate(10);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'id');
    }
}
