<?php

namespace Modules\FlowBuilder\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FlowLog extends Model {
    use HasFactory;

    protected $guarded = [];
    public $timestamps = true;
}