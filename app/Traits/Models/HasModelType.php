<?php 
namespace App\Traits\Models;

/**
 * * Contact  , etc [model name]
 */
trait HasModelType 
{
	public function getModelType()
	{
		return $this->model_type ;
	}
	public function getModelTypeFormatted()
	{
		return __($this->model_type) ;
	}
	
	public function user()
	{
		$modelType = $this->model_type;
		return $this->belongsTo('App\Models\\'.$modelType,'model_id','id');
	}
	// public function getUserName():string 
	// {
	// 	return $this->user ? $this->user->getFullName() : __('N/A',[],getApiLang());
	// }
}
