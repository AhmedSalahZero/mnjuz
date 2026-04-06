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
		// logger('--from incrementStep'.$this->current_step);
        $this->current_step += 1;
        $this->save();
    }
}
