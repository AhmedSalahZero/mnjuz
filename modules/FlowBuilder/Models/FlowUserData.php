<?php

namespace Modules\FlowBuilder\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FlowUserData extends Model
{
    use HasFactory;
    
    protected $guarded = [];
    public $timestamps = true;

    public function incrementStep()
    {
        $this->current_step += 1;
        $this->save();
    }
}