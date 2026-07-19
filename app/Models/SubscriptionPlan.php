<?php

namespace App\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriptionPlan extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $guarded = [];
    public $timestamps = true;

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'plan_id', 'id');
    }

    public function listAll($searchTerm)
    {
        return $this->where('deleted_at', null)
                    ->where(function ($query) use ($searchTerm) {
                        $query->where('name', 'like', '%' . $searchTerm . '%');
                    })
                    ->latest()
                    ->paginate(10);
    }
}
