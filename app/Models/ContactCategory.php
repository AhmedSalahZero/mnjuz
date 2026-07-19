<?php

namespace App\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactCategory extends Model
{
    use HasFactory;
    use HasUuid;

    protected $guarded = [];
    public $timestamps = true;

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'id');
    }

    public function contacts()
    {
        return $this->belongsToMany(Contact::class, 'contact_category_contact', 'contact_category_id', 'contact_id')
            ->withTimestamps();
    }

    public function getAll($organizationId, $searchTerm)
    {
        return $this->where('organization_id', $organizationId)
            ->where(function ($query) use ($searchTerm) {
                $query->where('name', 'like', '%' . $searchTerm . '%');
            })
            ->latest()
            ->paginate(10);
    }

    public function getRow($uuid, $organizationId)
    {
        return $this->withCount(['contacts as contact_count' => function ($query) use ($organizationId) {
            $query->where('organization_id', $organizationId);
        }])
            ->where('uuid', $uuid)
            ->first();
    }

    public function countAll($organizationId)
    {
        return $this->where('organization_id', $organizationId)->count();
    }
}
