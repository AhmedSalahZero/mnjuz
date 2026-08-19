<?php

namespace App\Traits\Models;

use App\Helpers\HHelpers;
use App\Models\DeviceToken;

trait HasDeviceTokens
{
	public function deviceTokens()
	{
		return $this->hasMany(DeviceToken::class,'model_id','id')
		->where('model_type',HHelpers::getClassNameWithoutNameSpace($this));
	}
	public function getDeviceTokens():array 
	{
		return $this->deviceTokens->pluck('device_token')->toArray();
	}
	/**
	 * * fcm token [fcm_token]
	 */
	public function routeNotificationForFcm()
    {
        return $this->getDeviceTokens();
    }
	/**
	 * الوسيط ?string لا string: التطبيق قد يرسل device_token فارغاً، وتشديد
	 * النوع كان يُسقط تسجيل الدخول بـ TypeError بدل تخطّي المزامنة.
	 *
	 * ونخرج قبل الحذف لا بعده: الدالّة تمحو الرموز القائمة ثم تُنشئ الجديد،
	 * فرمزٌ فارغ يمرّ كان سيمحو رمز الجهاز الحالي ويستبدله بصفّ فارغ — فيفقد
	 * المستخدم إشعاراته بلا أن يظهر خطأ.
	 */
	public function syncDeviceTokens(?string $deviceToken , ?string $deviceName = null , ?string $deviceType = null ):void{
		if ($deviceToken === null || $deviceToken === '') {
			return;
		}

		$this->deviceTokens()->delete();
		$this->deviceTokens()->create([
				'model_type'=>HHelpers::getClassNameWithoutNameSpace($this) , 
				'device_token'=>$deviceToken ,
				'device_type'=>$deviceType,
				'device_name'=>$deviceName ,
			]);
			
		// $exists = in_array($deviceToken , $this->getDeviceTokens());
		// if(!$exists){
			
			
			
		// }
		
	}
	public function getDeviceName():string
	{
		$deviceToken = $this->deviceTokens->first();
		return $deviceToken && $deviceToken->device_name ? $deviceToken->device_name : __('N/A') ;
	}
	public function getDeviceType():string
	{
		$deviceToken = $this->deviceTokens->first();
		return $deviceToken && $deviceToken->device_type ? $deviceToken->device_type : __('N/A') ;
	}
}
