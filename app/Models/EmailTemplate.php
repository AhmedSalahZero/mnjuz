<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model {
    use HasFactory;

    protected $guarded = [];
    public $timestamps = false;

    public function listAll($searchTerm){
        return $this->where(function ($query) use ($searchTerm) {
                        $query->where('name', 'like', '%' . $searchTerm . '%')
                            ->orWhere('subject', 'like', '%' . $searchTerm . '%');
                    })
                    ->orderBy('updated_at', 'asc')
                    ->paginate(10);
    }
}