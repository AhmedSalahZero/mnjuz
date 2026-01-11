<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Page extends Model {
    use HasFactory;

    protected $guarded = [];
    public $timestamps = true;

    public function listAll($searchTerm){
        return $this->where(function ($query) use ($searchTerm) {
                        $query->where('name', 'like', '%' . $searchTerm . '%');
                    })
                    ->orderBy('updated_at', 'asc')
                    ->paginate(10);
    }
}