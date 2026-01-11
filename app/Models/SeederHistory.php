<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeederHistory extends Model
{
    use HasFactory;

    protected $table = 'seeder_histories';  // Table name
    protected $fillable = ['seeder_name'];  // Mass assignable columns

    public $timestamps = true;  // Using timestamps (created_at and updated_at)
}
