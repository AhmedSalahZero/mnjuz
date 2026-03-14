<?php 
namespace App\Helpers;


class HHelpers 
{
	public static function getClassNameWithoutNameSpace($object){
		$class_parts = explode('\\', get_class($object));
 		 return end($class_parts);
	}

}
