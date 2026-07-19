<?php

namespace App\Models;

use App\Traits\Models\HasModelType;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
* * هي عباره عن التوكنز الخاص بكل عميل او سواق
* * علشان نستخدم في الفاير بيز .. خلي بالك ان اليوزر الواحد ممكن يكون ليه
* * اكثر من توكن بحيث لو معاه اكثر من موبايل مثلا ..  اما الموبايل الواحد فا بيكون ليه توكن واحد
*/
class DeviceToken extends Model
{
    use  HasFactory,HasModelType ; 
	protected $guarded = [
		'id'
	];
	
	
}
