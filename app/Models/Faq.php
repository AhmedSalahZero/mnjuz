<?php

namespace App\Models;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Faq extends Model {
    use HasFactory;

    protected $guarded = [];
    public $timestamps = false;

    public function listAll($searchTerm)
    {
        $query = $this->where(function ($query) use ($searchTerm) {
                $query->where('question', 'like', '%' . $searchTerm . '%')
                    ->orwhere('answer', 'like', '%' . $searchTerm . '%');
            })
            ->latest()
            ->paginate(10);

        return $query;
    }
}
