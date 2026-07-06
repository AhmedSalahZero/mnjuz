<?php

namespace App\Models;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model {
    use HasFactory;
    use HasUuid;

    protected $guarded = [];
    public $timestamps = false;

    public function listAll($searchTerm){
        return $this->with(['user'])
                    ->where(function ($query) use ($searchTerm) {
                        $query->where('subject', 'like', '%' . $searchTerm . '%')
                            ->orWhere('recipient', 'like', '%' . $searchTerm . '%');
                    })
                    ->latest()
                    ->paginate(10);
    }

    public function user(){
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}