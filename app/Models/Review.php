<?php

namespace App\Models;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Review extends Model {
    use HasFactory;

    protected $guarded = [];
    public $timestamps = true;

    public function listAll($searchTerm)
    {
        $query = $this->where(function ($query) use ($searchTerm) {
                $query->where('name', 'like', '%' . $searchTerm . '%')
                    ->orwhere('position', 'like', '%' . $searchTerm . '%')
                    ->orwhere('review', 'like', '%' . $searchTerm . '%');
            })
            ->latest()
            ->paginate(10);

        return $query;
    }
}
