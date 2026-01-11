<?php

namespace App\Models;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContactGroup extends Model {
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $guarded = [];
    protected $dates = ['deleted_at'];
    public $timestamps = true;

    public function contacts(){
        return $this->belongsToMany(Contact::class, 'contact_contact_group', 'contact_group_id', 'contact_id')
            ->using(ContactContactGroup::class)
            ->withTimestamps();
    }

    public function countAllContacts($organizationId){
        return $this->contacts->where('organization_id', $organizationId)->count();
    }

    public function getAll($organizationId, $searchTerm)
    {
        return $this->where('organization_id', $organizationId)
            ->where('deleted_at', null)
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
        ->where('deleted_at', null)
        ->first();
    }

    public function countAll($organizationId)
    {
        return $this->where('organization_id', $organizationId)->where('deleted_at', null)->count();
    }
}
